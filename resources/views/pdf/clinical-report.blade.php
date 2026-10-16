<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinical Assessment Report - {{ $recordId }}</title>
    <style>
        @page {
            margin: 36px 36px 45px 36px;
            size: a4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1f2937;
            font-size: 11px;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #6366f1;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .clinic-brand {
            font-size: 18px;
            font-weight: bold;
            color: #4338ca;
            letter-spacing: -0.5px;
        }
        .clinic-sub {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 2px;
        }
        .doc-meta {
            text-align: right;
            font-size: 10px;
            color: #4b5563;
        }
        .doc-name {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
        }
        .badge {
            display: inline-block;
            background-color: #eef2ff;
            color: #4338ca;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
            margin-top: 14px;
            margin-bottom: 8px;
        }
        .two-col-table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-card {
            background-color: #f9fafb;
            border: 1px solid #f3f4f6;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 10px;
        }
        .data-label {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .data-value {
            font-size: 11px;
            color: #111827;
            font-weight: 600;
            margin-top: 1px;
        }
        .lesion-img {
            max-width: 130px;
            max-height: 130px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
        }
        .prob-bar-container {
            width: 100%;
            background-color: #f3f4f6;
            height: 6px;
            border-radius: 3px;
            margin-top: 2px;
            overflow: hidden;
        }
        .prob-bar {
            height: 6px;
            background-color: #6366f1;
            border-radius: 3px;
        }
        .rx-box {
            background-color: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 8px 10px;
            margin-top: 6px;
            border-radius: 0 4px 4px 0;
        }
        .rx-title {
            color: #92400e;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .disclaimer {
            font-size: 8px;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            margin-top: 20px;
            line-height: 1.35;
        }
        .sig-table {
            width: 100%;
            margin-top: 25px;
        }
    </style>
</head>
<body>

    <!-- Header Table -->
    <table class="header-table">
        <tr>
            <td style="vertical-align: top;">
                <div class="clinic-brand">DermAssist Clinical</div>
                <div class="clinic-sub">{{ $doctorName ? 'Clinical Dermatological Assessment Report' : 'AI Lesion Pre-Assessment Summary' }}</div>
                <div style="margin-top: 4px;">
                    <span class="badge">{{ $doctorName ? 'Official Clinical Record' : 'Patient Self-Assessment' }}</span>
                    <span style="font-size: 9px; color: #6b7280; margin-left: 6px;">Ref: #{{ substr($recordId, 0, 8) }}</span>
                </div>
            </td>
            <td class="doc-meta" style="vertical-align: top;">
                @if($doctorName)
                    <div class="doc-name">Dr. {{ $doctorName }}</div>
                    @if($doctorAffiliation)
                        <div>{{ $doctorAffiliation }}</div>
                    @endif
                @else
                    <div class="doc-name" style="color: #6b7280; font-size: 11px;">Patient Self-Scan</div>
                    <div style="font-size: 9px; color: #9ca3af;">No Prescribing Doctor Assigned</div>
                @endif
                <div>Date: {{ $dateFormatted }}</div>
            </td>
        </tr>
    </table>

    <!-- Patient Information Grid -->
    <div class="section-title">Patient Profile</div>
    <table class="two-col-table info-card">
        <tr>
            <td style="width: 33%; padding: 4px 6px;">
                <div class="data-label">Patient Name</div>
                <div class="data-value">{{ $patientName }}</div>
            </td>
            <td style="width: 33%; padding: 4px 6px;">
                <div class="data-label">Age / Sex</div>
                <div class="data-value">{{ $patientAge ?? 'N/A' }} &bull; {{ $patientSex ? ucfirst($patientSex) : 'N/A' }}</div>
            </td>
            <td style="width: 34%; padding: 4px 6px;">
                <div class="data-label">Patient ID / Contact</div>
                <div class="data-value">{{ $patientContact ?? substr($patientUuid, 0, 8) }}</div>
            </td>
        </tr>
    </table>

    <!-- Lesion Examination & AI Inference -->
    <div class="section-title">Dermatological Scan & AI Triage Inference</div>
    <table class="two-col-table" style="margin-bottom: 10px;">
        <tr>
            @if($imageBase64)
            <td style="width: 140px; vertical-align: top; padding-right: 12px;">
                <img src="{{ $imageBase64 }}" class="lesion-img" alt="Lesion Scan">
            </td>
            @endif
            <td style="vertical-align: top;">
                <div class="info-card" style="margin-bottom: 6px;">
                    <table style="width: 100%;">
                        <tr>
                            <td>
                                <div class="data-label">Primary AI Detected Condition</div>
                                <div class="data-value" style="font-size: 13px; color: #4338ca;">{{ $primaryCondition }}</div>
                            </td>
                            <td style="text-align: right;">
                                <div class="data-label">AI Inference Confidence</div>
                                <div class="data-value" style="font-size: 13px; color: #059669;">{{ $confidencePercent }}%</div>
                            </td>
                        </tr>
                    </table>
                </div>

                @if(!empty($probabilities))
                <div style="font-size: 9px; font-weight: bold; color: #6b7280; text-transform: uppercase; margin-bottom: 4px;">Top Differential Probabilities</div>
                @foreach(array_slice($probabilities, 0, 4) as $prob)
                <div style="margin-bottom: 4px;">
                    <table style="width: 100%; font-size: 9px;">
                        <tr>
                            <td>{{ $prob['label'] }}</td>
                            <td style="text-align: right; font-weight: bold;">{{ $prob['value'] }}%</td>
                        </tr>
                    </table>
                    <div class="prob-bar-container">
                        <div class="prob-bar" style="width: {{ $prob['value'] }}%;"></div>
                    </div>
                </div>
                @endforeach
                @endif
            </td>
        </tr>
    </table>

    <!-- Clinical Assessment Notes -->
    <div class="section-title">Physician Clinical Assessment</div>
    <div class="info-card" style="margin-bottom: 8px;">
        <table class="two-col-table">
            <tr>
                <td style="padding: 4px 6px;">
                    <div class="data-label">Final Clinical Diagnosis</div>
                    <div class="data-value" style="color: #111827; font-size: 12px;">
                        {{ $finalDiagnosis ?? $primaryCondition }}
                    </div>
                </td>
            </tr>
            @if($historyOfPresentIllness)
            <tr>
                <td style="padding: 4px 6px; border-top: 1px solid #f3f4f6;">
                    <div class="data-label">History of Present Illness (HPI)</div>
                    <div style="font-size: 10px; color: #374151; margin-top: 2px;">{{ $historyOfPresentIllness }}</div>
                </td>
            </tr>
            @endif
            @if($physicalExam)
            <tr>
                <td style="padding: 4px 6px; border-top: 1px solid #f3f4f6;">
                    <div class="data-label">Physical Examination Findings</div>
                    <div style="font-size: 10px; color: #374151; margin-top: 2px;">{{ $physicalExam }}</div>
                </td>
            </tr>
            @endif
        </table>
    </div>

    <!-- Treatment Plan & Prescriptions -->
    @if($prescription || $patientEducation || $followUpDate || $followUpInstructions)
    <div class="section-title">Treatment Plan & Prescription (Rx)</div>
    @if($prescription)
    <div class="rx-box">
        <div class="rx-title">&bull; Prescription & Medication Orders</div>
        <div style="font-size: 11px; font-weight: 500; color: #78350f; white-space: pre-wrap;">{{ $prescription }}</div>
    </div>
    @endif

    @if($patientEducation || $followUpDate || $followUpInstructions)
    <div class="info-card" style="margin-top: 6px;">
        <table class="two-col-table">
            @if($patientEducation)
            <tr>
                <td style="padding: 4px 6px;">
                    <div class="data-label">Patient Education & Lifestyle Care</div>
                    <div style="font-size: 10px; color: #374151; margin-top: 2px;">{{ $patientEducation }}</div>
                </td>
            </tr>
            @endif
            @if($followUpDate || $followUpInstructions)
            <tr>
                <td style="padding: 4px 6px; border-top: 1px solid #f3f4f6;">
                    <div class="data-label">Follow-up Instructions</div>
                    <div style="font-size: 10px; color: #374151; margin-top: 2px;">
                        @if($followUpDate)<strong>Scheduled Date:</strong> {{ $followUpDate }}<br>@endif
                        {{ $followUpInstructions }}
                    </div>
                </td>
            </tr>
            @endif
        </table>
    </div>
    @endif
    @endif

    <!-- Doctor Signature & Stamp -->
    @if($doctorName)
    <table class="sig-table">
        <tr>
            <td style="width: 55%; vertical-align: bottom;">
                <div style="font-size: 8px; color: #6b7280;">
                    Document Generated via DermAssist Clinical AI System<br>
                    Verification Hash: {{ hash('sha256', $recordId . ($doctorName ?? 'system')) }}
                </div>
            </td>
            <td style="width: 45%; text-align: center; vertical-align: bottom;">
                <div style="border-bottom: 1px solid #111827; width: 180px; margin: 0 auto 4px auto; height: 35px;"></div>
                <div style="font-size: 11px; font-weight: bold; color: #111827;">Dr. {{ $doctorName }}</div>
                <div style="font-size: 9px; color: #4b5563;">Licensed Medical Professional</div>
            </td>
        </tr>
    </table>
    @else
    <table class="sig-table">
        <tr>
            <td style="width: 100%; text-align: center; vertical-align: bottom; padding: 10px 0;">
                <div style="font-size: 8px; color: #6b7280; border-top: 1px dashed #d1d5db; padding-top: 6px;">
                    Digital Pre-Assessment Record Generated via DermAssist AI Engine &bull; Record Hash: {{ hash('sha256', $recordId . 'patient-summary') }}<br>
                    This assistive summary is provided for clinical triage and consultation preparation. Not an official signed medical prescription.
                </div>
            </td>
        </tr>
    </table>
    @endif

    <!-- Disclaimer -->
    <div class="disclaimer">
        CONFIDENTIAL MEDICAL RECORD &bull; PRODUCED BY DERMASSIST CLINICAL AI SYSTEM &bull;
        This report is generated for authorized patient care and medical documentation. AI inference models assist in diagnostic triage and lesion classification under physician review. Any clinical diagnosis and prescription herein are rendered by the licensed attending physician.
    </div>

</body>
</html>
