<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function overview(ReportService $reports): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        return response()->json([
            'data' => $reports->overview(),
        ]);
    }

    public function aging(ReportService $reports): JsonResponse
    {
        $this->authorize('viewAny', Loan::class);

        return response()->json([
            'data' => $reports->aging(),
        ]);
    }
}
