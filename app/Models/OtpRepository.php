<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * One-time codes.
 *
 * Rows outlive the code they carry. The resend cooldown and the hourly
 * ceilings are both counted from this table, so deleting a spent row would
 * hand the caller a fresh allowance; expired rows are swept on a schedule
 * instead, long after they could influence either limit.
 */
final class OtpRepository extends BaseRepository
{
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO otp_codes
                (phone, purpose, code_hash, max_attempts, expires_at, ip_address, user_agent, created_at)
             VALUES
                (:phone, :purpose, :code_hash, :max_attempts, :expires_at, :ip, :ua, :created_at)',
            [
                'phone'        => $data['phone'],
                'purpose'      => $data['purpose'],
                'code_hash'    => $data['code_hash'],
                'max_attempts' => $data['max_attempts'],
                'expires_at'   => $data['expires_at'],
                'ip'           => $data['ip_address'] ?? null,
                'ua'           => mb_substr((string) ($data['user_agent'] ?? ''), 0, 512),
                'created_at'   => $this->now(),
            ]
        );
    }

    /**
     * The code a verification attempt must be checked against: the newest one
     * for this number and purpose that is still live.
     *
     * Only one can ever match, because issuing a code retires its
     * predecessors — so an old code cannot be presented after a resend.
     */
    public function findLive(string $phone, string $purpose): ?array
    {
        return $this->selectOne(
            'SELECT * FROM otp_codes
             WHERE phone = :phone AND purpose = :purpose
               AND used_at IS NULL AND consumed_at IS NULL AND expires_at > :now
             ORDER BY id DESC LIMIT 1',
            ['phone' => $phone, 'purpose' => $purpose, 'now' => $this->now()]
        );
    }

    /** The last code issued, live or not — what the resend cooldown is measured from. */
    public function lastIssuedAt(string $phone, string $purpose): ?string
    {
        $row = $this->selectOne(
            'SELECT created_at FROM otp_codes
             WHERE phone = :phone AND purpose = :purpose
             ORDER BY id DESC LIMIT 1',
            ['phone' => $phone, 'purpose' => $purpose]
        );
        return $row === null ? null : (string) $row['created_at'];
    }

    /** Codes sent to this number in the trailing window, across every purpose. */
    public function countSince(string $phone, string $since): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM otp_codes WHERE phone = :phone AND created_at >= :since',
            ['phone' => $phone, 'since' => $since]
        )['c'] ?? 0);
    }

    /** Codes this address asked for in the trailing window, across every number. */
    public function countForIpSince(string $ip, string $since): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM otp_codes WHERE ip_address = :ip AND created_at >= :since',
            ['ip' => $ip, 'since' => $since]
        )['c'] ?? 0);
    }

    /**
     * Retires every live code for a number and purpose.
     *
     * Called before a new one is issued, so a resend genuinely replaces the
     * previous code rather than leaving two valid at once.
     */
    public function consumeLive(string $phone, string $purpose): int
    {
        return $this->execute(
            'UPDATE otp_codes SET consumed_at = :now
             WHERE phone = :phone AND purpose = :purpose
               AND used_at IS NULL AND consumed_at IS NULL',
            ['now' => $this->now(), 'phone' => $phone, 'purpose' => $purpose]
        );
    }

    /**
     * Records a wrong guess and returns the new count.
     *
     * The increment and the read are separate statements, so two requests
     * racing could both read the same total; the WHERE clause on the update
     * is what actually enforces the ceiling, and the returned count is only
     * used to tell the student how many tries are left.
     */
    public function registerAttempt(int $id): int
    {
        $this->execute('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id', ['id' => $id]);
        return (int) ($this->selectOne('SELECT attempts FROM otp_codes WHERE id = :id', ['id' => $id])['attempts'] ?? 0);
    }

    public function markUsed(int $id): void
    {
        $this->execute('UPDATE otp_codes SET used_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    public function markConsumed(int $id): void
    {
        $this->execute('UPDATE otp_codes SET consumed_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    /**
     * Attaches a short-lived ticket to a code that has just been accepted.
     *
     * The password-reset form runs in a second request, and the browser must
     * not be the thing that says whose password is being changed. The ticket
     * is the server's own record of "this number proved itself a moment ago";
     * only its hash is stored.
     */
    public function attachTicket(int $id, string $ticketHash, string $expiresAt): void
    {
        $this->execute(
            'UPDATE otp_codes SET ticket_hash = :hash, ticket_expires_at = :expires WHERE id = :id',
            ['hash' => $ticketHash, 'expires' => $expiresAt, 'id' => $id]
        );
    }

    public function findByTicket(string $ticketHash, string $purpose): ?array
    {
        return $this->selectOne(
            'SELECT * FROM otp_codes
             WHERE ticket_hash = :hash AND purpose = :purpose AND ticket_expires_at > :now
             LIMIT 1',
            ['hash' => $ticketHash, 'purpose' => $purpose, 'now' => $this->now()]
        );
    }

    public function clearTicket(int $id): void
    {
        $this->execute(
            'UPDATE otp_codes SET ticket_hash = NULL, ticket_expires_at = NULL WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Drops rows old enough that they can no longer affect a cooldown or an
     * hourly ceiling. A day is comfortably past both.
     */
    public function purgeExpired(): int
    {
        return $this->execute(
            'DELETE FROM otp_codes WHERE created_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - 86400)]
        );
    }
}
