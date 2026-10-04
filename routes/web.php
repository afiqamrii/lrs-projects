<?php

use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClarificationController;
use App\Http\Controllers\ClientContactController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ExtractionController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\InquiryDocumentController;
use App\Http\Controllers\MailboxSettingsController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\PublicInquiryController;
use App\Http\Controllers\SourcingController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\PrivateProcessingHeaders;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Boost\Middleware\InjectBoost;

Route::withoutMiddleware(InjectBoost::class)->group(function (): void {
    Route::get('/request-quote', [PublicInquiryController::class, 'create'])->middleware('throttle:public-page')->name('public-inquiries.create');
    Route::post('/request-quote', [PublicInquiryController::class, 'store'])->middleware('throttle:public-intake')->name('public-inquiries.store');
    Route::post('/request-quote/draft', [PublicInquiryController::class, 'draft'])->middleware('throttle:public-intake')->name('public-inquiries.draft');
    Route::get('/request-quote/received', [PublicInquiryController::class, 'receipt'])->name('public-inquiries.receipt');
    Route::post('/request-quote/received/resend', [PublicInquiryController::class, 'resend'])->middleware('throttle:public-resend')->name('public-inquiries.resend');
    Route::get('/request-quote/confirm', [PublicInquiryController::class, 'confirmation'])->middleware('throttle:public-page')->name('public-inquiries.confirm');
    Route::post('/request-quote/confirm', [PublicInquiryController::class, 'confirm'])->middleware('throttle:public-confirm')->name('public-inquiries.confirm.store');
});

Route::redirect('/', '/overview');
Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:sensitive')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset', ['token' => $token, 'email' => request('email')]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:sensitive')->name('password.update');
});
Route::middleware(['auth', 'active', 'auth.session'])->group(function (): void {

    Route::resource('clients', ClientController::class)->except('destroy');
    Route::get('/clients/{client}/status', [ClientController::class, 'statusForm'])->name('clients.status');
    Route::patch('/clients/{client}/status', [ClientController::class, 'status'])->name('clients.status.update');
    Route::resource('clients.contacts', ClientContactController::class)->only(['create', 'store', 'edit', 'update'])->scoped();
    Route::get('/inquiries/{inquiry}/public-contact', [PublicContactController::class, 'edit'])->name('inquiries.public-contact.edit');
    Route::patch('/inquiries/{inquiry}/public-contact', [PublicContactController::class, 'update'])->name('inquiries.public-contact.update');
    Route::post('/inquiries/{inquiry}/public-contact/resend', [PublicContactController::class, 'resend'])->middleware('throttle:public-resend')->name('inquiries.public-contact.resend');
    Route::resource('inquiries', InquiryController::class)->except('destroy');
    Route::post('/inquiries/{inquiry}/transition', [InquiryController::class, 'transition'])->name('inquiries.transition');
    Route::post('/inquiries/{inquiry}/communications', [InquiryController::class, 'communicate'])->name('inquiries.communications.store');
    Route::get('/inquiries/{inquiry}/versions/{version}', [InquiryController::class, 'version'])->scopeBindings()->name('inquiries.versions.show');
    Route::post('/inquiries/{inquiry}/clarifications', [ClarificationController::class, 'store'])->name('inquiries.clarifications.store');
    Route::get('/inquiries/{inquiry}/clarifications/{clarification}', [ClarificationController::class, 'show'])->scopeBindings()->name('inquiries.clarifications.show');
    Route::patch('/inquiries/{inquiry}/clarifications/{clarification}', [ClarificationController::class, 'update'])->scopeBindings()->name('inquiries.clarifications.update');
    Route::post('/inquiries/{inquiry}/clarifications/{clarification}/approve', [ClarificationController::class, 'approve'])->scopeBindings()->name('inquiries.clarifications.approve');
    Route::post('/inquiries/{inquiry}/clarifications/{clarification}/communicated', [ClarificationController::class, 'communicated'])->scopeBindings()->name('inquiries.clarifications.communicated');
    Route::post('/inquiries/{inquiry}/documents', [InquiryDocumentController::class, 'store'])->name('inquiries.documents.store');
    Route::get('/inquiries/{inquiry}/documents/{document}', [InquiryDocumentController::class, 'show'])->scopeBindings()->name('inquiries.documents.show');
    Route::get('/inquiries/{inquiry}/documents/{document}/file', [InquiryDocumentController::class, 'file'])->scopeBindings()->name('inquiries.documents.file');
    Route::patch('/inquiries/{inquiry}/documents/{document}', [InquiryDocumentController::class, 'update'])->scopeBindings()->name('inquiries.documents.update');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/overview', [WorkspaceController::class, 'overview'])->name('overview');
    Route::resource('vendors', VendorController::class)->except('destroy');
    Route::get('/vendors/{vendor}/status', [VendorController::class, 'statusForm'])->name('vendors.status');
    Route::patch('/vendors/{vendor}/status', [VendorController::class, 'status'])->name('vendors.status.update');
    Route::resource('vendors.contacts', ContactController::class)->only(['create', 'store', 'edit', 'update'])->scoped();
    Route::get('/profile', [WorkspaceController::class, 'profile'])->name('profile');
    Route::patch('/profile', [WorkspaceController::class, 'updateProfile'])->middleware('throttle:sensitive')->name('profile.update');
    Route::middleware('can:manage-company')->group(function (): void {
        Route::get('/settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::patch('/settings', [WorkspaceController::class, 'updateSettings'])->name('settings.update');
    });
    Route::middleware('can:viewAny,'.User::class)->group(function (): void {
        Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
        Route::post('/staff/{staff}/password-setup', [StaffController::class, 'reset'])->middleware('throttle:sensitive')->name('staff.reset');
    });
});

Route::middleware(['auth', 'active', 'auth.session', PrivateProcessingHeaders::class])->withoutMiddleware(InjectBoost::class)->group(function (): void {
    Route::get('/inquiries/{inquiry}/sourcing', [SourcingController::class, 'index'])->name('inquiries.sourcing');
    Route::post('/inquiries/{inquiry}/sourcing', [SourcingController::class, 'select'])->name('inquiries.sourcing.select');
    Route::get('/inquiries/{inquiry}/rfqs/{rfq}', [SourcingController::class, 'edit'])->name('rfqs.edit');
    Route::patch('/inquiries/{inquiry}/rfqs/{rfq}', [SourcingController::class, 'save'])->name('rfqs.save');
    Route::get('/inquiries/{inquiry}/rfqs/{rfq}/review', [SourcingController::class, 'review'])->name('rfqs.review');
    Route::post('/inquiries/{inquiry}/rfqs/{rfq}/approve', [SourcingController::class, 'approve'])->name('rfqs.approve');
    Route::post('/inquiries/{inquiry}/rfqs/{rfq}/state', [SourcingController::class, 'state'])->name('rfqs.state');
    Route::get('/inquiries/{inquiry}/rfqs/{rfq}/output', [SourcingController::class, 'output'])->name('rfqs.output');
    Route::get('/inquiries/{inquiry}/rfqs/{rfq}/attachments/{document}', [SourcingController::class, 'attachment'])->name('rfqs.attachment');
    Route::post('/inquiries/{inquiry}/rfqs/{rfq}/manual-send', [SourcingController::class, 'manual'])->name('rfqs.manual');
    Route::get('/inquiries/{inquiry}/rfqs/{rfq}/wording/scope', [SourcingController::class, 'scope'])->name('rfqs.ai.scope');
    Route::post('/inquiries/{inquiry}/rfqs/{rfq}/wording', [SourcingController::class, 'requestWording'])->name('rfqs.ai.store');
    Route::post('/inquiries/{inquiry}/rfqs/{rfq}/wording/{run}/apply', [SourcingController::class, 'applyWording'])->name('rfqs.ai.apply');
    Route::get('/inquiries/{inquiry}/extraction', [ExtractionController::class, 'show'])->name('inquiries.extraction');
    Route::post('/inquiries/{inquiry}/extraction', [ExtractionController::class, 'extract'])->name('inquiries.extraction.store');
    Route::post('/inquiries/{inquiry}/ai/scope', [ExtractionController::class, 'scope'])->name('inquiries.ai.scope');
    Route::post('/inquiries/{inquiry}/ai', [ExtractionController::class, 'requestProposals'])->name('inquiries.ai.store');
    Route::post('/inquiries/{inquiry}/ai/{run}/preview', [ExtractionController::class, 'preview'])->name('inquiries.ai.preview');
    Route::post('/inquiries/{inquiry}/ai/{run}/apply', [ExtractionController::class, 'apply'])->name('inquiries.ai.apply');
    Route::get('/inquiries/{inquiry}/extraction/{documentRun}/pages/{page}', [ExtractionController::class, 'page'])->whereNumber('page')->name('inquiries.extraction.page');
    Route::middleware('can:manage-company')->group(function (): void {
        Route::get('/settings/ai', [AiSettingsController::class, 'show'])->name('settings.ai');
        Route::patch('/settings/ai', [AiSettingsController::class, 'update'])->name('settings.ai.update');
        Route::post('/settings/ai/check', [AiSettingsController::class, 'check'])->middleware('throttle:sensitive')->name('settings.ai.check');
        Route::post('/settings/ai/runs/{run}/reconcile', [AiSettingsController::class, 'reconcile'])->name('settings.ai.reconcile');
    });
});

Route::middleware(['auth', 'active', 'auth.session', PrivateProcessingHeaders::class])->withoutMiddleware(InjectBoost::class)->group(function (): void {
    Route::get('/mail', [MailController::class, 'index'])->name('mail.index');
    Route::get('/mail/outgoing', [MailController::class, 'outgoing'])->name('mail.outgoing');
    Route::get('/mail/messages/{message}', [MailController::class, 'show'])->name('mail.message');
    Route::post('/mail/messages/{message}/review', [MailController::class, 'review'])->name('mail.review');
    Route::post('/mail/messages/{message}/retry', [MailController::class, 'retry'])->name('mail.retry');
    Route::get('/mail/messages/{message}/attachments/{attachment}', [MailController::class, 'attachment'])->name('mail.attachment');
    Route::get('/inquiries/{inquiry}/email', [MailController::class, 'timeline'])->name('inquiries.mail');
    Route::get('/mail/preview/{kind}/{id}', [MailController::class, 'preview'])->whereIn('kind', ['rfq', 'clarification'])->whereNumber('id')->name('mail.preview');
    Route::post('/mail/preview/{kind}/{id}/authorize', [MailController::class, 'authorizeEnvelope'])->whereIn('kind', ['rfq', 'clarification'])->whereNumber('id')->name('mail.authorize');
    Route::post('/mail/envelopes/{envelope}/enqueue', [MailController::class, 'enqueue'])->name('mail.enqueue');
    Route::get('/mail/dispatches/{dispatch}', [MailController::class, 'dispatch'])->name('mail.dispatch');
    Route::post('/mail/dispatches/{dispatch}/recover', [MailController::class, 'recover'])->name('mail.recover');
    Route::middleware('can:manage-company')->group(function (): void {
        Route::get('/settings/mailbox', [MailboxSettingsController::class, 'show'])->name('settings.mailbox');
        Route::patch('/settings/mailbox', [MailboxSettingsController::class, 'update'])->name('settings.mailbox.update');
        Route::post('/settings/mailbox/connect', [MailboxSettingsController::class, 'connect'])->name('settings.mailbox.connect');
        Route::get('/settings/mailbox/callback', [MailboxSettingsController::class, 'callback'])->name('settings.mailbox.callback');
        Route::post('/settings/mailbox/action', [MailboxSettingsController::class, 'action'])->name('settings.mailbox.action');
        Route::post('/settings/mailbox/folders', [MailboxSettingsController::class, 'folder'])->name('settings.mailbox.folder');
        Route::post('/settings/mailbox/folders/{folder}', [MailboxSettingsController::class, 'folderAction'])->name('settings.mailbox.folder-action');
    });
});
