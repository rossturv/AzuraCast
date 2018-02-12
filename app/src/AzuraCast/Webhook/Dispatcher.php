<?php
namespace AzuraCast\Webhook;

use Entity;

class Dispatcher
{
    /** @var Connector\ConnectorInterface[] */
    protected $connectors;

    /**
     * @param Connector\ConnectorInterface[] $connectors
     */
    public function __construct(array $connectors)
    {
        $this->connectors = $connectors;
    }

    /**
     * Determine which webhooks to dispatch for a given change in Now Playing data, and dispatch them.
     *
     * @param Entity\Station $station
     * @param Entity\Api\NowPlaying $np_old
     * @param Entity\Api\NowPlaying $np_new
     */
    public function dispatch(Entity\Station $station, Entity\Api\NowPlaying $np_old, Entity\Api\NowPlaying $np_new): void
    {
        if (APP_TESTING_MODE) {
            \App\Debug::log('In testing mode; no webhooks dispatched.');
            return;
        }

        // Compile list of connectors for the station. Always dispatch to the local websocket receiver.
        $connectors = [];
        $connectors[] = [
            'type' => 'local',
            'triggers' => ['all'],
            'config' => [],
        ];

        // Assemble list of webhooks for the station
        $station_webhooks = $station->getWebhooks();

        if ($station_webhooks->count() > 0) {
            foreach($station_webhooks as $webhook) {
                /** @var Entity\StationWebhook $webhook */
                if ($webhook->isEnabled()) {
                    $connectors[] = [
                        'type' => $webhook->getType(),
                        'triggers' => $webhook->getTriggers() ?: ['all'],
                        'config' => $webhook->getConfig(),
                    ];
                }
            }
        }

        // Determine which events should be triggered as a result of this change.
        $to_trigger = ['all'];

        if ($np_old->now_playing->song->id !== $np_new->now_playing->song->id) {
            $to_trigger[] = 'song_changed';
        }

        if ($np_old->listeners->current > $np_new->listeners->current) {
            $to_trigger[] = 'listener_lost';
        } elseif ($np_old->listeners->current < $np_new->listeners->current) {
            $to_trigger[] = 'listener_gained';
        }

        if ($np_old->live->is_live === false && $np_new->live->is_live === true) {
            $to_trigger[] = 'live_connect';
        } elseif ($np_old->live->is_live === true && $np_new->live->is_live === false) {
            $to_trigger[] = 'live_disconnect';
        }

        \App\Debug::log('Triggering events: '.implode(', ', $to_trigger));

        // Trigger all appropriate webhooks.
        foreach($connectors as $connector) {
            if (!isset($this->connectors[$connector['type']])) {
                \App\Debug::log(sprintf('Webhook connector "%s" does not exist; skipping.', $connector['type']));
                continue;
            }

            if (!empty(array_intersect($to_trigger, $connector['triggers']))) {
                \App\Debug::log(sprintf('Dispatching connector "%s".', $connector['type']));

                /** @var Connector\ConnectorInterface $connector_obj */
                $connector_obj = $this->connectors[$connector['type']];

                $connector_obj->dispatch($station, $np_new);
            }
        }
    }

    public static function getTriggers()
    {
        return [
            'song_changed' => _('Any time the currently playing song changes'),
            'listener_gained' => _('Any time the listener count increases'),
            'listener_lost' => _('Any time the listener count decreases'),
            'live_connect' => _('Any time a live streamer/DJ connects to the stream'),
            'live_disconnect' => _('Any time a live streamer/DJ disconnects from the stream'),
        ];
    }

}