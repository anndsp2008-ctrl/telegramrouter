<?php declare(strict_types=1);

namespace App\Reporting;

interface FootballResultsClient
{
    public function providerKey(): string;
    public function fixturesByDate(string $date): array;
    public function teamsSearch(string $search): array;
    public function fixturesByTeamDate(int $teamId, string $date): array;
    public function fixture(int $fixtureId): array;
    public function statistics(int $fixtureId): array;
}
