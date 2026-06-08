<?php

namespace App\Http\Controllers;

use App\Service\RecordService;
use Illuminate\Http\Request;

class RecordController extends Controller
{
    private RecordService $recordService;

    public function __construct(RecordService $recordService)
    {
        $this->recordService = $recordService;
    }

    public function index(Request $request)
    {
        return $this->recordService->listRecords($request->user());
    }
}