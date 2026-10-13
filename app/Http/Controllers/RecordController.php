<?php

namespace App\Http\Controllers;

use App\Service\ClinicalReportPdfService;
use App\Service\RecordService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RecordController extends Controller
{
    public function __construct(
        private RecordService $recordService,
        private ClinicalReportPdfService $pdfService
    ) {}

    public function index(Request $request)
    {
        return $this->recordService->listRecords($request->user());
    }

    public function exportPdf(Request $request, string $uuid): Response
    {
        return $this->pdfService->generatePdf($uuid, $request->user());
    }
}
