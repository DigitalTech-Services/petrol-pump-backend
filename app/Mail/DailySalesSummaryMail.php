<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DailySalesSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $totals;

    public function __construct(
        public Carbon $date,
        public string $stationName,
        public string $managerName,
        public string $ownerName,
        public Collection $sales,
    ) {
        $sum = fn(string $col) => round((float) $sales->sum($col), 2);

        $this->totals = [
            'ms'       => $sum('ms_volume'),
            'hsd'      => $sum('hsd_volume'),
            'speed'    => $sum('speed_volume'),
            'revenue'  => $sum('revenue'),
            'cash'     => $sum('cash'),
            'phone_pe' => $sum('phone_pe'),
            'card'     => $sum('card'),
            'credit'   => $sum('credit_sale'),
            'expenses' => $sum('expenses'),
            'balance'  => $sum('balance'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Daily Sales Summary — {$this->stationName} — {$this->date->format('d M Y')}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-sales-summary');
    }
}
