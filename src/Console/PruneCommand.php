<?php

namespace ErnestDefoe\Hangout\Console;

use ErnestDefoe\Hangout\Settings;
use ErnestDefoe\Hangout\World;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/**
 * hangout:prune: forgets talk older than the admin's "keep" days, and sweeps
 * out anyone whose browser went quiet even when nobody else is polling.
 * Scheduled every few minutes.
 */
class PruneCommand extends AbstractCommand
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly World $world,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hangout:prune')->setDescription('Forget old Hangout talk and disconnect quiet browsers.');
    }

    protected function fire(): int
    {
        $this->world->sweep();
        $before = date('Y-m-d H:i:s', time() - $this->settings->keepDays() * 86400);
        $total = 0;
        do {
            $ids = $this->db->table('hangout_events')->where('created_at', '<', $before)->orderBy('id')->limit(1000)->pluck('id')->all();
            if ($ids) {
                $this->db->table('hangout_inbox')->whereIn('event_id', $ids)->delete();
                $total += $this->db->table('hangout_events')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === 1000);
        $this->info("Forgot $total events.");

        return 0;
    }
}
