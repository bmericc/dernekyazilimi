<?php

namespace Modules\Seminar\Mail\Concerns;

use Illuminate\Support\HtmlString;
use Modules\Seminar\Models\SeminarOffers;
use Modules\Seminar\Models\SeminarRequests;

/**
 * Placeholder values of the seminar emails.
 */
trait SeminarValues
{
    protected function requestValues(SeminarRequests $request): array
    {
        $online = $request->seminar_type === 'online';
        $dates = $request->seminar_start_date->format('d.m.Y')
            .($request->seminar_start_date->isSameDay($request->seminar_end_date) ? '' : ' – '.$request->seminar_end_date->format('d.m.Y'));
        $institution = $request->organizationRecord?->name ?? $request->organization;

        $details = '<ul><li><strong>Seminer:</strong> '.e($request->seminarSubject->subject).'</li>'
            .'<li><strong>Kurum/kuruluş:</strong> '.e($institution).'</li>'
            .'<li><strong>Tür:</strong> '.($online ? 'Online' : 'Yüz yüze').'</li>'
            .($online ? '' : '<li><strong>Yer:</strong> '.e($request->location).'</li>')
            .'<li><strong>Tarih aralığı:</strong> '.e($dates).'</li></ul>';

        return [
            'name' => trim($request->user->name.' '.$request->user->surname),
            'email' => $request->user->email,
            'phone' => $request->user->phone_number,
            'subject' => $request->seminarSubject->subject,
            'institution' => $institution,
            'type' => $online ? 'Online' : 'Yüz yüze',
            'location' => $online ? '' : $request->location,
            'dates' => $dates,
            'details' => new HtmlString($details),
        ];
    }

    protected function offerValues(SeminarOffers $offer): array
    {
        return [
            'name' => trim($offer->user->name.' '.$offer->user->surname),
            'email' => $offer->user->email,
            'subject' => $offer->seminarSubject?->subject ?? $offer->seminarSubjectProposal?->subject,
            'type' => $offer->seminar_type,
            'summary' => $offer->summary,
            'biography' => $offer->biography,
        ];
    }
}
