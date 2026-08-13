<?php

namespace Modules\Resume\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Resume\Models\Resume;
use Modules\Resume\Services\ATSAnalyzerService;
use Modules\Resume\Exceptions\ATSAnalysisException;
use Illuminate\Support\Facades\Log;
class ATSController extends Controller
{
    public function __construct(
        private ATSAnalyzerService $atsAnalyzer
    ) {
    }

   public function check(Request $request, string $id)
{
    try {
        $resume = Resume::findOrFail($id);

        if ($resume->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to analyze this resume.',
            ], 403);
        }

        $resumeData = $resume->cv_resumejson ?? [];

        $template = $request->input('template');
        $template = is_string($template) ? strtolower($template) : null;

        Log::info('ATS: Resume loaded', [
            'resume_id' => $id,
            'data_type' => gettype($resumeData),
            'template' => $template,
        ]);

        $result = $this->atsAnalyzer->analyze($resumeData, template: $template);

        Log::info('ATS: Analysis completed', [
            'resume_id' => $id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ATS analysis completed successfully.',
            'data' => $result,
        ]);

    } catch (\Throwable $e) {

        Log::error('ATS ERROR', [
            'resume_id' => $id,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $safeMessage = $e instanceof ATSAnalysisException
            ? $e->getSafeMessage()
            : 'CV analysis failed. Please try again shortly.';

        return response()->json([
            'success' => false,
            'message' => $safeMessage,
        ], 500);
    }
}
}