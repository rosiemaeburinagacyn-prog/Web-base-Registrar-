<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\DocumentRequestFile;
use App\Models\DocumentRequest;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\RegistrarStatusNotification;
use App\Services\OfficialReceiptGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route($this->dashboardRouteNameFor($request->user()));
    }

    public function studentDashboard(Request $request): View
    {
        $requests = $request->user()
            ->documentRequests()
            ->with(['academicYear', 'items', 'latestPayment', 'completedFiles'])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('student.dashboard', [
            'requests' => $requests,
            'documentTypes' => DocumentRequest::DOCUMENT_TYPES,
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('name')->get(),
            'semesters' => DocumentRequest::SEMESTERS,
            'notifications' => $request->user()->notifications()->latest()->limit(5)->get(),
        ]);
    }

    public function adminDashboard(Request $request): View
    {
        return $this->staffDashboard($request, 'admin');
    }

    public function registrarDashboard(Request $request): View
    {
        return $this->staffDashboard($request, 'registrar');
    }

    private function staffDashboard(Request $request, string $context): View
    {
        $query = DocumentRequest::query()
            ->with(['user', 'academicYear', 'items', 'latestPayment.cashier', 'latestPayment.verifier', 'completedFiles'])
            ->latest();

        if ($context === 'registrar') {
            $query->whereIn('payment_status', [DocumentRequest::PAYMENT_PAID, 'Approved']);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('student_name', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhere('document_type', 'like', "%{$search}%")
                    ->orWhereHas('items', function ($query) use ($search) {
                        $query->where('document_type', 'like', "%{$search}%");
                    })
                    ->orWhereHas('user', function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('student_number', 'like', "%{$search}%")
                            ->orWhere('school_email', 'like', "%{$search}%");
                    });
            });
        }

        $paymentStatus = $request->query('payment_status');
        if (in_array($paymentStatus, [
            DocumentRequest::PAYMENT_PENDING,
            DocumentRequest::PAYMENT_PAID,
            DocumentRequest::PAYMENT_FAILED,
            DocumentRequest::PAYMENT_REFUNDED,
            'Approved',
        ], true)) {
            $query->where('payment_status', $paymentStatus);
        }

        $requestStatus = $request->query('request_status');
        if (in_array($requestStatus, DocumentRequest::REQUEST_STATUSES, true)) {
            $query->where('request_status', $requestStatus);
        }

        return view('admin.dashboard', [
            'requests' => $query->paginate(10)->withQueryString(),
            'paymentStatuses' => [
                DocumentRequest::PAYMENT_PENDING,
                DocumentRequest::PAYMENT_PAID,
                DocumentRequest::PAYMENT_FAILED,
                DocumentRequest::PAYMENT_REFUNDED,
            ],
            'requestStatuses' => DocumentRequest::REQUEST_STATUSES,
            'pendingPaymentCount' => Payment::query()
                ->where('payment_status', Payment::STATUS_PENDING)
                ->count(),
            'dashboardRouteName' => $context.'.dashboard',
            'requestRoutePrefix' => $context,
            'canVerifyPayments' => false,
            'studentVerificationQueue' => User::query()
                ->where('role', User::ROLE_STUDENT)
                ->where('verification_status', User::VERIFICATION_PENDING)
                ->latest('verification_submitted_at')
                ->limit(10)
                ->get(),
            'verifiedStudentCount' => User::query()
                ->where('role', User::ROLE_STUDENT)
                ->where('verification_status', User::VERIFICATION_APPROVED)
                ->count(),
            'dashboardTitle' => $context === 'admin' ? 'Registrar Admin Dashboard' : 'Registrar Dashboard',
            'dashboardDescription' => $context === 'admin'
                ? 'Manage users, student verification, reports, and secure document requests.'
                : 'Review verified students, process paid requests, and release completed documents.',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canRequestDocuments()) {
            throw ValidationException::withMessages([
                'verification' => 'Your school ID verification must be approved before submitting document requests.',
            ]);
        }

        if (! $user->student_number) {
            throw ValidationException::withMessages([
                'student_number' => 'Your account is missing an official student number. Contact the registrar office.',
            ]);
        }

        $validated = $request->validate([
            'documents' => ['nullable', 'array'],
            'documents.*.quantity' => ['nullable', 'integer', 'min:0', 'max:20'],
            'document_type' => ['nullable', 'string', Rule::in(DocumentRequest::documentTypeKeys())],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('is_active', true)],
            'semester' => ['required', 'string', Rule::in(DocumentRequest::SEMESTERS)],
        ]);

        $academicYear = AcademicYear::query()->findOrFail($validated['academic_year_id']);
        $selectedItems = $this->selectedDocumentItems($request);
        $amount = collect($selectedItems)->sum('subtotal');

        $documentRequest = DocumentRequest::create([
            'user_id' => $user->id,
            'request_reference' => $this->requestReference(),
            'student_name' => $user->name,
            'student_id' => $user->student_number,
            'document_type' => $selectedItems[0]['document_type'],
            'amount' => $amount,
            'academic_year_id' => $academicYear->id,
            'academic_year' => $academicYear->name,
            'semester' => $validated['semester'],
            'payment_status' => DocumentRequest::PAYMENT_PENDING,
            'request_status' => DocumentRequest::STATUS_PENDING,
        ]);

        $documentRequest->items()->createMany($selectedItems);

        return redirect()
            ->route('request.payment.show', $documentRequest)
            ->with('success', 'Document request submitted. Continue to secure payment checkout.');
    }

    public function pay(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        abort_unless((int) $documentRequest->user_id === (int) $request->user()->id, 403);

        return redirect()->route('request.payment.show', $documentRequest);
    }

    public function submitVerification(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isStudent(), 403);

        $validated = $request->validate([
            'school_id' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
            'selfie_id' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ]);

        $oldSchoolId = $user->school_id_path;
        $oldSelfie = $user->selfie_id_path;

        $schoolIdPath = $validated['school_id']->store('student-verifications', 'public');
        $selfiePath = $validated['selfie_id']->store('student-verifications', 'public');

        $user->update([
            'school_id_path' => $schoolIdPath,
            'selfie_id_path' => $selfiePath,
            'verification_status' => User::VERIFICATION_PENDING,
            'verification_submitted_at' => now(),
            'verification_reviewed_at' => null,
            'verification_reviewed_by' => null,
            'verification_note' => null,
        ]);

        $user->studentProfile?->update([
            'verification_status' => User::VERIFICATION_PENDING,
        ]);

        foreach ([$oldSchoolId, $oldSelfie] as $oldPath) {
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_REGISTRAR])
            ->get()
            ->each(fn (User $staff) => $staff->notify(new RegistrarStatusNotification(
                'Student verification submitted',
                $user->name.' submitted school ID verification for review.',
                null
            )));

        $this->ensurePublicStorageLink();

        return redirect()
            ->route('student.dashboard')
            ->with('success', 'Verification submitted. Wait for registrar approval before requesting documents.');
    }

    public function approveStudentVerification(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $validated = $request->validate([
            'verification_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $student->update([
            'verification_status' => User::VERIFICATION_APPROVED,
            'verification_reviewed_at' => now(),
            'verification_reviewed_by' => $request->user()->id,
            'verification_note' => $validated['verification_note'] ?? null,
        ]);

        $student->studentProfile?->update([
            'verification_status' => User::VERIFICATION_APPROVED,
        ]);

        $student->notify(new RegistrarStatusNotification(
            'Verification approved',
            'Your school ID verification was approved. You can now request documents.',
            null
        ));

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Student verification approved.');
    }

    public function rejectStudentVerification(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $validated = $request->validate([
            'verification_note' => ['required', 'string', 'max:1000'],
        ]);

        $student->update([
            'verification_status' => User::VERIFICATION_REJECTED,
            'verification_reviewed_at' => now(),
            'verification_reviewed_by' => $request->user()->id,
            'verification_note' => $validated['verification_note'],
        ]);

        $student->studentProfile?->update([
            'verification_status' => User::VERIFICATION_REJECTED,
        ]);

        $student->notify(new RegistrarStatusNotification(
            'Verification rejected',
            'Your school ID verification was rejected: '.$validated['verification_note'],
            null
        ));

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Student verification rejected.');
    }

    public function receipt(Request $request, DocumentRequest $documentRequest, OfficialReceiptGenerator $receiptGenerator): View
    {
        $this->authorizeReceiptAccess($request, $documentRequest);

        $documentRequest->load(['items', 'latestPayment.cashier', 'latestPayment.verifier']);
        $payment = $documentRequest->latestPayment;
        abort_unless($payment && $documentRequest->isPaid(), 404);

        $payment = $receiptGenerator->ensure($payment);

        return view('payments.receipt', compact('documentRequest', 'payment'));
    }

    public function downloadReceipt(Request $request, DocumentRequest $documentRequest, OfficialReceiptGenerator $receiptGenerator): StreamedResponse
    {
        $this->authorizeReceiptAccess($request, $documentRequest);

        $documentRequest->load(['items', 'latestPayment.cashier', 'latestPayment.verifier']);
        $payment = $documentRequest->latestPayment;
        abort_unless($payment && $documentRequest->isPaid(), 404);

        $payment = $receiptGenerator->ensure($payment, true);
        abort_unless($payment->official_receipt_path && Storage::disk('public')->exists($payment->official_receipt_path), 404);

        return Storage::disk('public')->download(
            $payment->official_receipt_path,
            $payment->receipt_number.'.pdf',
            ['Content-Type' => 'application/pdf']
        );
    }

    public function approve(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ensurePaidRequest($documentRequest);
        $this->ensureNotRejected($documentRequest);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $documentRequest->update([
            'request_status' => DocumentRequest::STATUS_PENDING,
            'admin_note' => $validated['admin_note'] ?? $documentRequest->admin_note,
            'reviewed_at' => now(),
        ]);

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Request reviewed.');
    }

    public function reject(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $validated = $request->validate([
            'admin_note' => ['required', 'string', 'max:1000'],
        ]);

        $documentRequest->update([
            'request_status' => DocumentRequest::STATUS_PAYMENT_REJECTED,
            'admin_note' => $validated['admin_note'],
            'reviewed_at' => now(),
        ]);

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Request rejected.');
    }

    public function markProcessing(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ensurePaidRequest($documentRequest);
        $this->ensureNotRejected($documentRequest);

        if (! in_array($documentRequest->request_status, [
                DocumentRequest::STATUS_PENDING,
                DocumentRequest::STATUS_FOR_PROCESSING,
                'Payment Approved',
            ], true)) {
            throw ValidationException::withMessages([
                'request' => 'Only paid pending requests can be marked for processing.',
            ]);
        }

        $documentRequest->update([
            'request_status' => DocumentRequest::STATUS_FOR_PROCESSING,
        ]);

        $documentRequest->user?->notify(new RegistrarStatusNotification(
            'Document processing started',
            'The registrar has started processing '.$documentRequest->request_reference.'.',
            $documentRequest
        ));

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Request marked as processing.');
    }

    public function uploadDocument(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ensurePaidRequest($documentRequest);
        $this->ensureNotRejected($documentRequest);

        if (! in_array($documentRequest->request_status, [
                DocumentRequest::STATUS_PROCESSING,
                DocumentRequest::STATUS_READY_FOR_PICKUP,
            ], true)) {
            throw ValidationException::withMessages([
                'document_files' => 'The request must be processing before uploading completed PDF documents.',
            ]);
        }

        $request->validate([
            'document_files' => ['nullable', 'array', 'max:20'],
            'document_files.*' => ['file', 'mimes:pdf', 'max:5120'],
            'document_file' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $files = collect($request->file('document_files', []))->filter();

        if ($request->hasFile('document_file')) {
            $files->push($request->file('document_file'));
        }

        if ($files->isEmpty()) {
            throw ValidationException::withMessages([
                'document_files' => 'Upload at least one completed PDF document.',
            ]);
        }

        $firstPath = $documentRequest->uploaded_file;

        foreach ($files as $file) {
            $path = $file->store('registrar-documents', 'public');

            $documentRequest->completedFiles()->create([
                'uploaded_by' => $request->user()->id,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_at' => now(),
            ]);

            $firstPath ??= $path;
        }

        $documentRequest->update([
            'uploaded_file' => $firstPath,
            'request_status' => DocumentRequest::STATUS_READY_FOR_PICKUP,
            'completed_at' => now(),
        ]);

        $documentRequest->user?->notify(new RegistrarStatusNotification(
            'Documents ready for pickup',
            'The registrar completed '.$documentRequest->request_reference.'. Wait for release confirmation.',
            $documentRequest
        ));

        $this->ensurePublicStorageLink();

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'PDF documents uploaded. Mark the request released when the student receives them.');
    }

    public function release(Request $request, DocumentRequest $documentRequest): RedirectResponse
    {
        $this->ensurePaidRequest($documentRequest);
        $this->ensureNotRejected($documentRequest);

        if (! in_array($documentRequest->request_status, [
            DocumentRequest::STATUS_READY_FOR_PICKUP,
            DocumentRequest::STATUS_RELEASED,
            'Ready for Download',
        ], true)) {
            throw ValidationException::withMessages([
                'request' => 'Only ready-for-pickup requests can be marked released.',
            ]);
        }

        if (! $documentRequest->uploaded_file && ! $documentRequest->completedFiles()->exists()) {
            throw ValidationException::withMessages([
                'request' => 'Upload the completed PDF document before releasing the request.',
            ]);
        }

        $documentRequest->update([
            'request_status' => DocumentRequest::STATUS_RELEASED,
            'released_at' => now(),
        ]);

        $documentRequest->user?->notify(new RegistrarStatusNotification(
            'Documents released',
            'The registrar marked '.$documentRequest->request_reference.' as released.',
            $documentRequest
        ));

        return redirect()
            ->route($this->dashboardRouteNameFor($request->user()))
            ->with('success', 'Request marked released.');
    }

    public function download(Request $request, DocumentRequest $documentRequest): StreamedResponse
    {
        $this->authorizeDocumentAccess($request, $documentRequest);

        $downloadableStatuses = [
            DocumentRequest::STATUS_RELEASED,
            'Ready for Download',
            'Completed',
        ];

        abort_unless(in_array($documentRequest->request_status, $downloadableStatuses, true), 404);
        abort_unless((bool) $documentRequest->uploaded_file, 404);
        abort_unless(Storage::disk('public')->exists($documentRequest->uploaded_file), 404);

        $extension = pathinfo($documentRequest->uploaded_file, PATHINFO_EXTENSION) ?: 'pdf';
        $fileName = Str::slug($documentRequest->student_id.'-'.$documentRequest->document_type).'.'.$extension;

        return Storage::disk('public')->download($documentRequest->uploaded_file, $fileName);
    }

    public function downloadFile(Request $request, DocumentRequest $documentRequest, DocumentRequestFile $documentRequestFile): StreamedResponse
    {
        $this->authorizeDocumentAccess($request, $documentRequest);
        abort_unless((int) $documentRequestFile->document_request_id === (int) $documentRequest->id, 404);

        $downloadableStatuses = [
            DocumentRequest::STATUS_RELEASED,
            'Ready for Download',
            'Completed',
        ];

        abort_unless(in_array($documentRequest->request_status, $downloadableStatuses, true), 404);
        abort_unless(Storage::disk('public')->exists($documentRequestFile->file_path), 404);

        $fileName = $documentRequestFile->original_name
            ?: Str::slug($documentRequest->student_id.'-'.$documentRequest->document_type.'-'.$documentRequestFile->id).'.pdf';

        return Storage::disk('public')->download($documentRequestFile->file_path, $fileName);
    }

    private function authorizeDocumentAccess(Request $request, DocumentRequest $documentRequest): void
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin()
            || $user->isRegistrar()
            || (int) $documentRequest->user_id === (int) $user->id,
            403
        );
    }

    private function authorizeReceiptAccess(Request $request, DocumentRequest $documentRequest): void
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin()
            || $user->isRegistrar()
            || $user->isCashier()
            || (int) $documentRequest->user_id === (int) $user->id,
            403
        );
    }

    /**
     * @return array<int, array{document_type: string, quantity: int, unit_price: float, subtotal: float}>
     */
    private function selectedDocumentItems(Request $request): array
    {
        $selectedItems = [];
        $documents = $request->input('documents', []);

        if (is_array($documents)) {
            foreach ($documents as $documentType => $document) {
                if (! in_array($documentType, DocumentRequest::documentTypeKeys(), true)) {
                    throw ValidationException::withMessages([
                        'documents' => 'One or more selected document types are invalid.',
                    ]);
                }

                $quantity = (int) data_get($document, 'quantity', 0);

                if ($quantity < 1) {
                    continue;
                }

                $unitPrice = DocumentRequest::amountFor($documentType);

                $selectedItems[] = [
                    'document_type' => $documentType,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $unitPrice * $quantity,
                ];
            }
        }

        if ($selectedItems === [] && $request->filled('document_type')) {
            $documentType = (string) $request->input('document_type');
            $unitPrice = DocumentRequest::amountFor($documentType);

            $selectedItems[] = [
                'document_type' => $documentType,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'subtotal' => $unitPrice,
            ];
        }

        if ($selectedItems === []) {
            throw ValidationException::withMessages([
                'documents' => 'Select at least one document and quantity.',
            ]);
        }

        return $selectedItems;
    }

    private function ensurePaidRequest(DocumentRequest $documentRequest): void
    {
        if (! $documentRequest->isPaid()) {
            throw ValidationException::withMessages([
                'request' => 'This request must have a paid gateway transaction before the registrar can process it.',
            ]);
        }
    }

    private function ensureNotRejected(DocumentRequest $documentRequest): void
    {
        if ($documentRequest->isRejected()) {
            throw ValidationException::withMessages([
                'request' => 'Rejected requests cannot continue through the processing workflow.',
            ]);
        }
    }

    private function requestReference(): string
    {
        do {
            $reference = 'REQ-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
        } while (DocumentRequest::where('request_reference', $reference)->exists());

        return $reference;
    }

    private function dashboardRouteNameFor($user): string
    {
        if ($user->isAdmin()) {
            return 'admin.dashboard';
        }

        if ($user->isRegistrar()) {
            return 'registrar.dashboard';
        }

        if ($user->isCashier()) {
            return 'cashier.dashboard';
        }

        return 'student.dashboard';
    }

    private function ensurePublicStorageLink(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (File::exists(public_path('storage')) || is_link(public_path('storage'))) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
