<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum HerdrSessionDoctorIssueCode: string implements DoctorIssueCode
{
    case ProcessUnhealthy = 'herdr.process_unhealthy';
    case ListenerUnhealthy = 'herdr.listener_unhealthy';
    case SessionUnhealthy = 'herdr.session_unhealthy';
    case InspectionFailed = 'herdr.inspection_failed';
    case NodeUnreachable = 'herdr.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Herdr;
    }
}
