<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class StockVariationAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public Carbon $date;
    public string $fuelLabel;

    public function __construct(
        public array $entry,
        public string $stationName,
        public string $managerName,
        public string $ownerName,
        public float $thresholdPct,
    ) {
        $this->date      = Carbon::parse($entry['date']);
        $this->fuelLabel = ['MS' => 'MS (Petrol)', 'HSD' => 'HSD (Diesel)', 'Speed' => 'Power (Premium)'][$entry['fuel_type']] ?? $entry['fuel_type'];
    }

    public function envelope(): Envelope
    {
        $kind = $this->entry['variation'] < 0 ? 'Shortage' : 'Excess';

        return new Envelope(
            subject: "⚠ Stock Variation Alert ({$kind} {$this->entry['variation_pct']}%) — {$this->fuelLabel} — {$this->stationName} — {$this->date->format('d M Y')}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.stock-variation-alert');
    }
}
