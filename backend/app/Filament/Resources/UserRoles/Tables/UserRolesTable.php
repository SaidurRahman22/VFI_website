<?php

namespace App\Filament\Resources\UserRoles\Tables;

use App\Enums\Role;
use App\Models\Partner\PartnerAgency;
use App\Models\User;
use App\Models\UserRole;
use App\Services\RoleAssignmentService;
use App\Support\StepUp;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class UserRolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('email')
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->toggleable(),
                TextColumn::make('status')->badge()->colors([
                    'success' => 'active', 'warning' => ['pending', 'invited'], 'danger' => 'suspended',
                ]),
                TextColumn::make('roles')->label('Active roles')->badge()
                    ->state(fn (User $r) => $r->roles->whereNull('revoked_at')
                        ->map(fn (UserRole $ur) => ($ur->role instanceof Role ? $ur->role->value : $ur->role)
                            .($ur->agency_id ? ' #'.$ur->agency_id : ''))
                        ->values()->all())
                    ->placeholder('—'),
                TextColumn::make('mfa_enrolled_at')->label('2FA')
                    ->formatStateUsing(fn ($state) => $state ? 'Enrolled' : 'No')
                    ->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('grant')
                    ->label('Grant role')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('primary')
                    ->modalHeading('Grant a role')
                    ->modalDescription('Roles decide what someone can reach. This is recorded against your account.')
                    ->schema([
                        Select::make('role')->label('Role')->required()
                            ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->value])->all())
                            ->live(),
                        Select::make('agency_id')->label('Agency')
                            ->options(fn () => PartnerAgency::orderBy('legal_name')->pluck('legal_name', 'id')->all())
                            ->searchable()
                            ->helperText('Required for the partner_* roles, and must be empty for the others.')
                            ->visible(fn ($get) => ($r = Role::tryFrom((string) $get('role'))) && $r->isTenantBound()),
                        TextInput::make('code')->label('Your 6-digit authenticator code')->required()
                            ->helperText('Re-confirms it is really you before the permissions change.'),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            $actor = auth()->user();
                            StepUp::assert($actor, $data['code'] ?? null, 'role_grant');
                            app(RoleAssignmentService::class)->grant(
                                $record,
                                Role::from($data['role']),
                                ($data['agency_id'] ?? null) ? (int) $data['agency_id'] : null,
                                $actor,
                            );
                            Notification::make()->success()->title('Role granted')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not granted')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('revoke')
                    ->label('Revoke role')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('danger')
                    ->visible(fn (User $r) => $r->roles->whereNull('revoked_at')->isNotEmpty())
                    ->schema(fn (User $record) => [
                        Select::make('assignment_id')->label('Role to remove')->required()
                            ->options($record->roles->whereNull('revoked_at')
                                ->mapWithKeys(fn (UserRole $ur) => [
                                    $ur->id => ($ur->role instanceof Role ? $ur->role->value : $ur->role)
                                        .($ur->agency_id ? ' (agency #'.$ur->agency_id.')' : ''),
                                ])->all()),
                        TextInput::make('code')->label('Your 6-digit authenticator code')->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            $actor = auth()->user();
                            StepUp::assert($actor, $data['code'] ?? null, 'role_revoke');
                            $assignment = UserRole::where('user_id', $record->id)
                                ->whereKey($data['assignment_id'])->firstOrFail();
                            app(RoleAssignmentService::class)->revoke($assignment, $actor);
                            Notification::make()->success()->title('Role revoked')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not revoked')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('history')
                    ->label('History')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (User $record) {
                        $rows = UserRole::where('user_id', $record->id)->orderByDesc('granted_at')->limit(50)->get();
                        if ($rows->isEmpty()) {
                            return new HtmlString('<p class="text-sm text-gray-500">This user has never held a role.</p>');
                        }
                        $html = '<div class="space-y-2 text-sm">';
                        foreach ($rows as $r) {
                            $role = $r->role instanceof Role ? $r->role->value : $r->role;
                            $state = $r->revoked_at
                                ? 'revoked '.$r->revoked_at->format('d M Y')
                                : 'active';
                            $html .= '<div class="rounded-lg border p-2"><b>'.e($role).'</b>'
                                .($r->agency_id ? ' · agency #'.(int) $r->agency_id : '')
                                .'<div class="text-xs text-gray-500">granted '
                                .e(optional($r->granted_at)->format('d M Y')).' · '.e($state).'</div></div>';
                        }

                        return new HtmlString($html.'</div>');
                    }),
            ]);
    }
}
