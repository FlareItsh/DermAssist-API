<?php

namespace App\Events;

use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScanAppealUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public mixed $appeal,
        public string $action = 'submitted',
        public ?string $doctorUuid = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admin.moderation'),
        ];

        if (! empty($this->doctorUuid)) {
            $channels[] = new PrivateChannel('user.'.$this->doctorUuid);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'ScanAppealUpdated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $appealData = $this->appeal instanceof Appeal
            ? (new AppealResource($this->appeal->loadMissing(['user', 'diagnosis.patient'])))->resolve()
            : $this->appeal;

        return [
            'action' => $this->action,
            'appeal' => $appealData,
            'appeal_uuid' => $this->appeal instanceof Appeal ? $this->appeal->uuid : ($this->appeal['uuid'] ?? null),
            'doctor_uuid' => $this->doctorUuid,
        ];
    }
}
