<?php

use App\Http\Controllers\Api\AboutPageContentController;
use App\Http\Controllers\Api\Admin\AboutPageContentAdminController;
use App\Http\Controllers\Api\Admin\ContactFeedbackAdminController;
use App\Http\Controllers\Api\Admin\ContactPageContentAdminController;
use App\Http\Controllers\Api\ContactFeedbackController;
use App\Http\Controllers\Api\ContactPageContentController;
use App\Http\Controllers\Api\Admin\CmsMediaUploadController;
use App\Http\Controllers\Api\Admin\HomepageContentAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HomepageContentController;
use App\Http\Controllers\Api\LmsAdminSummaryController;
use App\Http\Controllers\Api\LmsAnalyticsController;
use App\Http\Controllers\Api\LmsAnnouncementController;
use App\Http\Controllers\Api\LmsCourseController;
use App\Http\Controllers\Api\LmsInAppNotificationController;
use App\Http\Controllers\Api\LmsLeaderboardController;
use App\Http\Controllers\Api\LmsMetaController;
use App\Http\Controllers\Api\LmsInstructorController;
use App\Http\Controllers\Api\LmsStudentController;
use App\Http\Controllers\CmsVideoFileController;
use App\Http\Controllers\Api\PublicStorageController;
use App\Http\Controllers\Api\LmsProgramController;
use App\Http\Controllers\Api\LmsBatchEnrollController;
use App\Http\Controllers\Api\LmsBranchEnrollController;
use App\Http\Controllers\Api\LmsReviewScheduleController;
use App\Http\Controllers\Api\LmsEnrollmentFormOptionsController;
use App\Http\Controllers\Api\LmsHonorAwardDiscountController;
use App\Http\Controllers\Api\LmsPackageEnrollController;
use App\Http\Controllers\Api\LmsLearningModeController;
use App\Http\Controllers\Api\LmsQuizResultController;
use App\Http\Controllers\Api\LmsMyAssignmentController;
use App\Http\Controllers\Api\LmsMyQuizController;
use App\Http\Controllers\Api\LmsQuizSummaryController;
use App\Http\Controllers\Api\LmsGradebookController;
use App\Http\Controllers\Api\LmsAssignmentSummaryController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\Admin\BankPaymentMethodAdminController;
use App\Http\Controllers\Api\Admin\EwalletPaymentMethodAdminController;
use App\Http\Controllers\Api\LmsUserController;
use App\Http\Controllers\Api\V1\LmsAdminUploadController;
use App\Http\Controllers\Api\V1\LmsEnrollmentController;
use App\Http\Controllers\Api\V1\LmsEnrollmentPaymentController;
use App\Http\Controllers\Api\V1\LmsModuleController;
use App\Http\Controllers\Api\V1\LmsLessonMaterialController;
use App\Http\Controllers\Api\V1\LmsLessonVideoUploadController;
use App\Http\Controllers\Api\V1\LmsQuizController;
use App\Http\Controllers\Api\V1\LmsAssignmentController;
use App\Http\Controllers\Api\V1\LmsLessonProgressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Modular LMS JSON API. LMS routes require `Authorization: Bearer {token}`
| from Sanctum (`POST /api/login` / `/api/register`). Public: health + auth.
|--------------------------------------------------------------------------
*/

Route::get('/health', fn () => ['status' => 'ok']);

Route::middleware('throttle:12,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/signup', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public catalog reads (DB-backed) — no login required.
Route::get('/meta', [LmsMetaController::class, 'show']);
Route::get('/programs', [LmsProgramController::class, 'index']);
Route::get('/programs/{programPublicId}/stats', [LmsProgramController::class, 'stats']);
Route::get('/courses', [LmsCourseController::class, 'index']);
Route::get('/courses/{courseLookup}/detail', [LmsCourseController::class, 'show']);
Route::get('/courses/{coursePublicId}/stats', [LmsCourseController::class, 'stats']);
Route::get('/modules', [LmsModuleController::class, 'index']);
Route::get('/quizzes', [LmsQuizController::class, 'index']);
Route::get('/homepage-v2', [HomepageContentController::class, 'show']);
Route::get('/about-us', [AboutPageContentController::class, 'show']);
Route::get('/contact-page', [ContactPageContentController::class, 'show']);
Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
Route::get('/media/{publicId}/file', [CmsMediaUploadController::class, 'file']);
Route::get('/public-storage/file', [PublicStorageController::class, 'show']);
Route::get('/cms-videos/{filename}', [CmsVideoFileController::class, 'show'])
    ->where('filename', '[A-Za-z0-9._-]+');
Route::get('/lesson-materials/{publicId}/file', [LmsLessonMaterialController::class, 'download']);

Route::middleware('throttle:10,1')->post('/contact-feedback', [ContactFeedbackController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', [LmsUserController::class, 'show']);
    Route::match(['patch', 'post'], '/user', [LmsUserController::class, 'update']);
    Route::get('/notifications', [LmsInAppNotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [LmsInAppNotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{publicId}/read', [LmsInAppNotificationController::class, 'markRead']);
    Route::patch('/notifications/{publicId}/unread', [LmsInAppNotificationController::class, 'markUnread']);
    Route::get('/instructors', [LmsInstructorController::class, 'index']);

    Route::middleware('page:/setting-program')->group(function () {
        Route::get('/programs/{programPublicId}/applicants', [LmsProgramController::class, 'applicants']);
        Route::post('/programs', [LmsProgramController::class, 'store']);
        Route::patch('/programs/{programPublicId}', [LmsProgramController::class, 'update']);
        Route::delete('/programs/{programPublicId}', [LmsProgramController::class, 'destroy']);
    });

    Route::middleware('page:/setting-batch-enroll')->group(function () {
        Route::get('/batch-enrolls/{publicId}/applicants/export', [LmsBatchEnrollController::class, 'exportApplicants']);
        Route::get('/batch-enrolls/{publicId}/applicants', [LmsBatchEnrollController::class, 'applicants']);
        Route::get('/batch-enrolls', [LmsBatchEnrollController::class, 'index']);
        Route::post('/batch-enrolls', [LmsBatchEnrollController::class, 'store']);
        Route::patch('/batch-enrolls/{publicId}', [LmsBatchEnrollController::class, 'update']);
        Route::delete('/batch-enrolls/{publicId}', [LmsBatchEnrollController::class, 'destroy']);
    });

    Route::middleware('staff')->group(function () {
        Route::get('/branch-enrolls/{publicId}/applicants/export', [LmsBranchEnrollController::class, 'exportApplicants']);
        Route::get('/branch-enrolls/{publicId}/applicants', [LmsBranchEnrollController::class, 'applicants']);
        Route::get('/branch-enrolls', [LmsBranchEnrollController::class, 'index']);
        Route::post('/branch-enrolls', [LmsBranchEnrollController::class, 'store']);
        Route::patch('/branch-enrolls/{publicId}', [LmsBranchEnrollController::class, 'update']);
        Route::delete('/branch-enrolls/{publicId}', [LmsBranchEnrollController::class, 'destroy']);
    });

    Route::middleware('staff')->group(function () {
        Route::get('/review-schedules/{publicId}/applicants/export', [LmsReviewScheduleController::class, 'exportApplicants']);
        Route::get('/review-schedules/{publicId}/applicants', [LmsReviewScheduleController::class, 'applicants']);
        Route::get('/review-schedules', [LmsReviewScheduleController::class, 'index']);
        Route::post('/review-schedules', [LmsReviewScheduleController::class, 'store']);
        Route::patch('/review-schedules/{publicId}', [LmsReviewScheduleController::class, 'update']);
        Route::delete('/review-schedules/{publicId}', [LmsReviewScheduleController::class, 'destroy']);
    });

    Route::middleware('staff')->group(function () {
        Route::get('/honor-award-discounts/{publicId}/applicants/export', [LmsHonorAwardDiscountController::class, 'exportApplicants']);
        Route::get('/honor-award-discounts/{publicId}/applicants', [LmsHonorAwardDiscountController::class, 'applicants']);
        Route::get('/honor-award-discounts', [LmsHonorAwardDiscountController::class, 'index']);
        Route::post('/honor-award-discounts', [LmsHonorAwardDiscountController::class, 'store']);
        Route::patch('/honor-award-discounts/{publicId}', [LmsHonorAwardDiscountController::class, 'update']);
        Route::delete('/honor-award-discounts/{publicId}', [LmsHonorAwardDiscountController::class, 'destroy']);
    });

    Route::middleware('staff')->group(function () {
        Route::get('/package-enrolls/{publicId}/applicants/export', [LmsPackageEnrollController::class, 'exportApplicants']);
        Route::get('/package-enrolls/{publicId}/applicants', [LmsPackageEnrollController::class, 'applicants']);
        Route::get('/package-enrolls', [LmsPackageEnrollController::class, 'index']);
        Route::post('/package-enrolls', [LmsPackageEnrollController::class, 'store']);
        Route::patch('/package-enrolls/{publicId}', [LmsPackageEnrollController::class, 'update']);
        Route::delete('/package-enrolls/{publicId}', [LmsPackageEnrollController::class, 'destroy']);
    });

    Route::middleware('staff')->group(function () {
        Route::get('/learning-modes/{publicId}/applicants/export', [LmsLearningModeController::class, 'exportApplicants']);
        Route::get('/learning-modes/{publicId}/applicants', [LmsLearningModeController::class, 'applicants']);
        Route::get('/learning-modes', [LmsLearningModeController::class, 'index']);
        Route::post('/learning-modes', [LmsLearningModeController::class, 'store']);
        Route::patch('/learning-modes/{publicId}', [LmsLearningModeController::class, 'update']);
        Route::delete('/learning-modes/{publicId}', [LmsLearningModeController::class, 'destroy']);
    });

    Route::middleware('page:/setting-instructor')->group(function () {
        Route::get('/instructors/linkable-users', [LmsInstructorController::class, 'linkableUsers']);
        Route::post('/instructors', [LmsInstructorController::class, 'store']);
        Route::patch('/instructors/{userPublicUid}', [LmsInstructorController::class, 'update']);
        Route::delete('/instructors/{userPublicUid}', [LmsInstructorController::class, 'destroy']);
    });

    Route::middleware('page:/setting-student')->group(function () {
        Route::get('/students/linkable-users', [LmsStudentController::class, 'linkableUsers']);
        Route::get('/students', [LmsStudentController::class, 'index']);
        Route::post('/students', [LmsStudentController::class, 'store']);
        Route::patch('/students/{userPublicUid}', [LmsStudentController::class, 'update']);
        Route::delete('/students/{userPublicUid}', [LmsStudentController::class, 'destroy']);
    });

    Route::middleware('page:/setting-payment')->group(function () {
        Route::get('/admin/bank-payment-methods', [BankPaymentMethodAdminController::class, 'index']);
        Route::post('/admin/bank-payment-methods', [BankPaymentMethodAdminController::class, 'store']);
        Route::patch('/admin/bank-payment-methods/{publicId}', [BankPaymentMethodAdminController::class, 'update']);
        Route::delete('/admin/bank-payment-methods/{publicId}', [BankPaymentMethodAdminController::class, 'destroy']);

        Route::get('/admin/ewallet-payment-methods', [EwalletPaymentMethodAdminController::class, 'index']);
        Route::post('/admin/ewallet-payment-methods', [EwalletPaymentMethodAdminController::class, 'store']);
        Route::patch('/admin/ewallet-payment-methods/{publicId}', [EwalletPaymentMethodAdminController::class, 'update']);
        Route::delete('/admin/ewallet-payment-methods/{publicId}', [EwalletPaymentMethodAdminController::class, 'destroy']);
    });
    Route::post('/courses', [LmsCourseController::class, 'store']);
    Route::post('/courses/{coursePublicId}/modules', [LmsModuleController::class, 'store']);
    Route::patch('/courses/{coursePublicId}/modules/reorder', [LmsModuleController::class, 'reorder']);
    Route::patch('/modules/{modulePublicId}/lessons/reorder', [LmsModuleController::class, 'reorderLessons']);
    Route::post('/modules/{modulePublicId}/standalone-lessons', [LmsModuleController::class, 'storeStandaloneLesson']);
    Route::patch('/standalone-lessons/{publicId}', [LmsModuleController::class, 'updateStandaloneLesson']);
    Route::delete('/standalone-lessons/{publicId}', [LmsModuleController::class, 'destroyStandaloneLesson']);
    Route::patch('/courses/{publicId}', [LmsCourseController::class, 'update']);
    Route::get('/enrollment-form/options', [LmsEnrollmentFormOptionsController::class, 'show']);
    Route::get('/enrollment-payments', [LmsEnrollmentPaymentController::class, 'index']);
    Route::get('/enrollments/enrolled-courses', [LmsEnrollmentController::class, 'enrolledCourses']);
    Route::get('/enrollments/export', [LmsEnrollmentController::class, 'export']);
    Route::get('/enrollments', [LmsEnrollmentController::class, 'index']);
    Route::post('/modules/{modulePublicId}/quizzes', [LmsQuizController::class, 'storeForModule']);
    Route::post('/modules/{modulePublicId}/assignments', [LmsAssignmentController::class, 'storeForModule']);
    Route::patch('/quizzes/{publicId}', [LmsQuizController::class, 'update']);
    Route::patch('/assignments/{publicId}', [LmsAssignmentController::class, 'update']);
    Route::delete('/assignments/{publicId}', [LmsAssignmentController::class, 'destroy']);
    Route::get('/courses/{coursePublicId}/lesson-progress', [LmsLessonProgressController::class, 'index']);
    Route::post('/courses/{coursePublicId}/lesson-progress', [LmsLessonProgressController::class, 'complete']);
    Route::patch('/courses/{coursePublicId}/lesson-progress', [LmsLessonProgressController::class, 'heartbeat']);
    Route::get('/quiz-results', [LmsQuizResultController::class, 'index']);
    Route::get('/assignment-summaries', [LmsAssignmentSummaryController::class, 'index']);
    Route::get('/quiz-summaries', [LmsQuizSummaryController::class, 'index']);
    Route::get('/gradebook/courses', [LmsGradebookController::class, 'courses']);
    Route::get('/gradebook/courses/{coursePublicId}', [LmsGradebookController::class, 'show']);
    Route::get('/my-assignments', [LmsMyAssignmentController::class, 'index']);
    Route::get('/my-quizzes', [LmsMyQuizController::class, 'index']);
    Route::get('/assignments/{publicId}/students', [LmsAssignmentSummaryController::class, 'students']);
    Route::get('/assignments/{publicId}/leaderboard', [LmsAssignmentSummaryController::class, 'leaderboard']);
    Route::get('/quizzes/{publicId}/students', [LmsQuizSummaryController::class, 'students']);
    Route::get('/quizzes/{publicId}/leaderboard', [LmsQuizSummaryController::class, 'leaderboard']);
    Route::get('/leaderboard', [LmsLeaderboardController::class, 'show']);
    Route::get('/analytics', [LmsAnalyticsController::class, 'show']);
    Route::get('/admin', [LmsAdminSummaryController::class, 'show']);

    Route::post('/enrollments', [LmsEnrollmentController::class, 'store']);
    Route::get('/enrollments/{publicId}', [LmsEnrollmentController::class, 'show']);
    Route::get('/enrollments/{publicId}/payment-proof', [LmsEnrollmentController::class, 'downloadPaymentProof']);
    Route::post('/enrollments/{publicId}/partial-payments', [LmsEnrollmentController::class, 'storePartialPayment']);
    Route::patch('/enrollments/{publicId}/payments/{paymentId}/verification', [LmsEnrollmentController::class, 'verifyPayment']);
    Route::get('/enrollments/{publicId}/documents/{documentKey}', [LmsEnrollmentController::class, 'downloadDocument']);
    Route::patch('/enrollments/{publicId}', [LmsEnrollmentController::class, 'updateStatus']);

    Route::get('/quizzes/{publicId}/questions', [LmsQuizController::class, 'questions']);
    Route::get('/assignments/{publicId}/questions', [LmsAssignmentController::class, 'questions']);
    Route::post('/quizzes/{publicId}/attempts', [LmsQuizController::class, 'storeAttempt']);
    Route::post('/assignments/{publicId}/attempts', [LmsAssignmentController::class, 'storeAttempt']);

    Route::patch('/modules/{publicId}/visibility', [LmsModuleController::class, 'toggleVisibility']);
    Route::delete('/modules/{publicId}', [LmsModuleController::class, 'destroy']);
    Route::patch('/modules/{publicId}', [LmsModuleController::class, 'update']);

    Route::post('/modules/{modulePublicId}/lesson-materials', [LmsLessonMaterialController::class, 'storeForModule']);
    Route::post('/assignments/{assignmentPublicId}/lesson-materials', [LmsLessonMaterialController::class, 'storeForAssignment']);
    Route::post('/standalone-lessons/{publicId}/lesson-materials', [
        LmsLessonMaterialController::class,
        'storeForStandaloneLesson',
    ]);
    Route::get('/lesson-materials/{publicId}/playback', [LmsLessonMaterialController::class, 'playback']);
    Route::delete('/lesson-materials/{publicId}', [LmsLessonMaterialController::class, 'destroy']);

    Route::post('/video-uploads', [LmsLessonVideoUploadController::class, 'store']);
    Route::get('/video-uploads/{uploadId}', [LmsLessonVideoUploadController::class, 'show']);
    Route::post('/video-uploads/{uploadId}/chunks/{index}', [LmsLessonVideoUploadController::class, 'storeChunk'])
        ->whereNumber('index');
    Route::post('/video-uploads/{uploadId}/complete', [LmsLessonVideoUploadController::class, 'complete']);
    Route::delete('/video-uploads/{uploadId}', [LmsLessonVideoUploadController::class, 'destroy']);

    Route::post('/admin/uploads', [LmsAdminUploadController::class, 'store']);

    Route::middleware('page:/feedback')->group(function () {
        Route::get('/admin/contact-feedback', [ContactFeedbackAdminController::class, 'index']);
    });

    Route::middleware('page:/content-management')->group(function () {
        Route::get('/admin/homepage-v2', [HomepageContentAdminController::class, 'show']);
        Route::put('/admin/homepage-v2/{section}', [HomepageContentAdminController::class, 'update']);
        Route::post('/admin/homepage-v2/publish-all', [HomepageContentAdminController::class, 'publishAll']);
        Route::get('/admin/about-us', [AboutPageContentAdminController::class, 'show']);
        Route::put('/admin/about-us/{section}', [AboutPageContentAdminController::class, 'update']);
        Route::post('/admin/about-us/publish-all', [AboutPageContentAdminController::class, 'publishAll']);
        Route::get('/admin/contact-page', [ContactPageContentAdminController::class, 'show']);
        Route::put('/admin/contact-page/{section}', [ContactPageContentAdminController::class, 'update']);
        Route::post('/admin/contact-page/publish-all', [ContactPageContentAdminController::class, 'publishAll']);
        Route::post('/admin/upload', [CmsMediaUploadController::class, 'store']);
        Route::delete('/admin/media/{publicId}', [CmsMediaUploadController::class, 'destroy']);
    });

    Route::middleware('page:/announcement')->group(function () {
        Route::post('/announcements', [LmsAnnouncementController::class, 'store']);
    });
});
