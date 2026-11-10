<?php

namespace Tests\Support;

use App\Contracts\RouteProvider;

/**
 * A route provider that answers from a script and remembers what it was asked.
 *
 * Standing in for Google so the tests can prove the things that actually cost
 * money: that a second request inside the cooldown does not reach the
 * provider, that the ceiling stops it buying more, and that the rider's
 * language and the right stop's coordinates are what get sent.
 */
class FakeRouteProvider implements RouteProvider
{
    /** @var array<int, array{from: array{float, float}, to: array{float, float}, language: string|null}> */
    public array $asked = [];

    public bool $configured = true;

    /** @var array<string, mixed>|null */
    public ?array $answer = null;

    public function __construct()
    {
        $this->answer = self::route();
    }

    /** A short two-turn route, shaped as the driver returns one. */
    public static function route(): array
    {
        return [
            'polyline' => 'ynb}Amqs}MoAyB',
            'duration_seconds' => 540,
            'distance_metres' => 2300,
            'steps' => [
                [
                    'instruction' => 'Head north on Bypass Road',
                    'maneuver' => null,
                    'distance_metres' => 400,
                    'duration_seconds' => 90,
                    'end_latitude' => 9.9255,
                    'end_longitude' => 78.1198,
                ],
                [
                    'instruction' => 'Turn right onto 3rd Street',
                    'maneuver' => 'turn-right',
                    'distance_metres' => 200,
                    'duration_seconds' => 40,
                    'end_latitude' => 9.93,
                    'end_longitude' => 78.12,
                ],
            ],
        ];
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function between(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        return $this->answer === null ? null : [
            'polyline' => $this->answer['polyline'],
            'duration_seconds' => $this->answer['duration_seconds'],
            'distance_metres' => $this->answer['distance_metres'],
        ];
    }

    public function navigation(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        ?string $language = null,
    ): ?array {
        $this->asked[] = [
            'from' => [$fromLat, $fromLng],
            'to' => [$toLat, $toLng],
            'language' => $language,
        ];

        return $this->answer;
    }

    public function calls(): int
    {
        return count($this->asked);
    }

    /** @return array{from: array{float, float}, to: array{float, float}, language: string|null} */
    public function last(): array
    {
        return $this->asked[count($this->asked) - 1];
    }
}
