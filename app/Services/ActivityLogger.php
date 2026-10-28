<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class ActivityLogger
{
    public function log(
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = [],
        ?string $logName = null,
    ): Activity {
        $logger = activity();

        if ($logName) {
            $logger->useLog($logName);
        }

        if ($subject) {
            $logger->performedOn($subject);
        }

        if ($causer) {
            $logger->causedBy($causer);
        } elseif (auth()->check()) {
            $logger->causedBy(auth()->user());
        }

        if ($properties) {
            $logger->withProperties($properties);
        }

        return $logger->log($description);
    }
}
