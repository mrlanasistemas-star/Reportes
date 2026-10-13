<?php

namespace App\Mail;

use App\Models\EfectividadCobranzaRun;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo de fin de generación de Efectividad de Cobranza (éxito, bloqueo por
 * datos o error técnico). Nunca adjunta el Excel financiero ni incluye stack
 * traces / secretos: solo un enlace al módulo protegido (requiere sesión).
 */
class EfectividadCobranzaRunMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KIND_SUCCESS = 'success';

    public const KIND_BLOCKED = 'blocked';

    public const KIND_FAILED = 'failed';

    public const KIND_CANCELLED = 'cancelled';

    public function __construct(
        public EfectividadCobranzaRun $run,
        public ?User $user,
        public string $kind,
        public string $detailUrl,
    ) {}

    public static function subjectFor(string $kind): string
    {
        return match ($kind) {
            self::KIND_SUCCESS => 'Efectividad de Cobranza — Reporte disponible',
            self::KIND_BLOCKED => 'Efectividad de Cobranza — Se requiere corregir información',
            self::KIND_CANCELLED => 'Efectividad de Cobranza — Generación cancelada',
            default => 'Efectividad de Cobranza — No fue posible completar el reporte',
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->kind));
    }

    public function content(): Content
    {
        $run = $this->run->loadMissing(['period', 'branch']);
        $tz = 'America/Mexico_City';

        $criticalIssues = collect($run->issues ?? [])
            ->where('severity', 'critical')
            ->take(5)
            ->map(fn (array $i) => [
                'message' => $i['message'] ?? '',
                'column' => $i['expected_column'] ?? null,
                'file' => $i['file'] ?? null,
                'action' => $i['action'] ?? null,
            ])
            ->values()
            ->all();

        return new Content(
            view: 'emails.efectividad-cobranza-run',
            with: [
                'subject' => self::subjectFor($this->kind),
                'kind' => $this->kind,
                'user' => $this->user,
                'runId' => $run->id,
                'periodLabel' => $run->period?->label ?? '—',
                'branchLabel' => $run->branch?->name ?? 'Todas las sucursales (general)',
                'requestedAt' => ($run->queued_at ?? $run->created_at)?->copy()->setTimezone($tz)->format('d/m/Y H:i'),
                'finishedAt' => $run->finished_at?->copy()->setTimezone($tz)->format('d/m/Y H:i'),
                'statusLabel' => match ($this->kind) {
                    self::KIND_SUCCESS => 'Terminado correctamente',
                    self::KIND_BLOCKED => 'Bloqueado por datos',
                    self::KIND_CANCELLED => 'Cancelado',
                    default => 'Error',
                },
                'reason' => $this->kind === self::KIND_BLOCKED
                    ? ($criticalIssues[0]['message'] ?? $run->log)
                    : null,
                'issues' => $criticalIssues,
                'detailUrl' => $this->detailUrl,
            ],
        );
    }
}
