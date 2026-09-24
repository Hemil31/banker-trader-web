<?php

namespace App\Services;

use App\Contracts\Ipo\AllotmentProvider;
use App\Exceptions\IpoFlowException;
use App\Models\IpoAllotment;
use App\Models\IpoApplication;
use Carbon\CarbonImmutable;

/**
 * Runs an allotment check for one application: guards that the bid was placed
 * and the IPO's allotment date has arrived, asks the pluggable AllotmentProvider,
 * persists the latest result on ipo_allotments (one row per application) and
 * mirrors it onto the application status.
 */
class IpoAllotmentService
{
    public function __construct(protected AllotmentProvider $provider) {}

    public function check(IpoApplication $application): IpoAllotment
    {
        if ($application->status === 'draft') {
            throw new IpoFlowException('This application has not been submitted yet.');
        }

        $allotmentDate = $application->ipo?->allotment_date;

        if ($allotmentDate !== null && $allotmentDate->greaterThan(CarbonImmutable::now()->toDate())) {
            throw new IpoFlowException(
                'Allotment is not announced yet — check on or after '.$allotmentDate->toDateString().'.',
            );
        }

        $lookup = $this->provider->fetch(
            (string) $application->panCard?->pan_number,
            (string) ($application->application_number ?? ''),
            $application->ipo?->registrar,
        );

        $allotment = IpoAllotment::firstOrNew(['ipo_application_id' => $application->id]);
        $allotment->ipo_id = $application->ipo_id;
        $allotment->pan_number = (string) $application->panCard?->pan_number;
        $allotment->result = $lookup['result'] ?? 'pending';
        $allotment->shares_allotted = $lookup['shares_allotted'] ?? null;
        $allotment->source = $lookup['source'] ?? null;
        $allotment->checked_at = CarbonImmutable::now();
        $allotment->raw_payload = $lookup === [] ? null : $lookup;
        $allotment->attempts = (int) ($allotment->attempts ?? 0) + 1;
        $allotment->save();

        if (isset($lookup['result'])) {
            $application->update([
                'status' => $lookup['result'] === 'allotted' ? 'allotted' : 'not_allotted',
                'allotment_checked_at' => CarbonImmutable::now(),
            ]);
        }

        return $allotment->refresh()->loadMissing('application');
    }
}
