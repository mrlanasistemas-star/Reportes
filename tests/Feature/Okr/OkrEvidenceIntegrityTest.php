<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Tests punto 30 J/K — integridad evidencia↔Key Result y semana. */
it('rejects an evidence whose okr_key_result_id belongs to a DIFFERENT Objective', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $objectiveA = okrDraftObjective($user, okrBranch('Cordoba'), [100.0]);
    $objectiveB = okrDraftObjective($user, okrBranch('Orizaba'), [100.0]);
    $krFromB = $objectiveB->keyResults()->first();

    $file = UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf');
    $response = $this->actingAs($user)->post(route('okr.evidences.store', $objectiveA), [
        'file' => $file, 'okr_key_result_id' => $krFromB->id,
    ]);

    $response->assertSessionHasErrors('okr_key_result_id');
    expect($objectiveA->evidences()->count())->toBe(0);
});

it('rejects an evidence with a week_number beyond the Objective duration', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $objective = okrDraftObjective($user, okrBranch('Atlixco'), [100.0]); // duration_weeks = 8

    $file = UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf');
    $response = $this->actingAs($user)->post(route('okr.evidences.store', $objective), [
        'file' => $file, 'week_number' => 999,
    ]);

    $response->assertSessionHasErrors('week_number');
});

it('never assigns week 0 automatically to an evidence when the objective has not started yet', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $branch = okrBranch('Huamantla');
    $objective = \App\Models\OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Objective futuro para evidencia',
        'responsible_user_id' => $user->id, 'created_by' => $user->id,
        'start_date' => now()->addWeeks(3)->toDateString(), 'end_date' => now()->addWeeks(11)->toDateString(), 'duration_weeks' => 8,
        'lifecycle_status' => 'active', 'activated_at' => now(),
    ]);

    $file = UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf');
    $this->actingAs($user)->post(route('okr.evidences.store', $objective), ['file' => $file])->assertSessionHasNoErrors();

    $evidence = $objective->evidences()->first();
    expect($evidence->week_number)->toBeNull();
});
