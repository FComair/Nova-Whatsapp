const fs = require('fs');
function edit(p, pairs) { let s = fs.readFileSync(p, 'utf8'); for (const [a, b] of pairs) { if (!s.includes(a)) throw new Error(p + ' missing ' + a.slice(0, 60)); s = s.split(a).join(b); } fs.writeFileSync(p, s); }
edit('app/Modules/Staff/Services/InvitationService.php', [
  ["entityId: $practitioner?->id ?? $invitation->id", "entityId: $practitioner->id ?? $invitation->id"],
  ["$invitation->name ?? $practitioner?->name ?? $email", "$invitation->name ?? $practitioner->name ?? $email"],
]);
edit('app/Modules/Team/Services/TeamManager.php', [
  ["'email' => (string) $member->user_email,\n                'phone' => $member->phone ?? $member->user_phone,", "'email' => (string) $member->getAttribute('user_email'),\n                'phone' => $member->phone ?? $member->getAttribute('user_phone'),"],
  ["$member->last_login_at === null ? null : Carbon::parse($member->last_login_at)->toIso8601String()", "$member->getAttribute('last_login_at') === null ? null : Carbon::parse($member->getAttribute('last_login_at'))->toIso8601String()"],
  ["return (string) ($member->name ?? $practitioner?->name ?? $member->user_email);", "return (string) ($member->name ?? $practitioner->name ?? $member->getAttribute('user_email'));"],
]);
edit('app/Modules/Team/Support/Capabilities.php', [["if (is_string($capability) && self::exists($capability) && is_bool($value)) {", "if (self::exists($capability) && is_bool($value)) {"]]);
edit('app/Modules/Tenancy/Http/Middleware/SetTenantContext.php', [["use App\Modules\Tenancy\Models\Organization;", "use App\Modules\Team\Support\Capabilities;\nuse App\Modules\Tenancy\Models\Organization;"]]);
