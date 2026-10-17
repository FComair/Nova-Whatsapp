<?php

declare(strict_types=1);

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Isolation entre clients : la RLS PostgreSQL est la barrière, pas le code applicatif (docs/01 §0, docs/02 §6).
 */
beforeEach(function (): void {
    [$this->orgA, $this->orgB] = $this->asOwner(fn () => [
        Organization::factory()->create(['name' => 'Salon A']),
        Organization::factory()->create(['name' => 'Cabinet B']),
    ]);

    $this->asOwner(function (): void {
        Location::factory()->create(['org_id' => $this->orgA->id, 'name' => 'Site A1']);
        Location::factory()->create(['org_id' => $this->orgA->id, 'name' => 'Site A2']);
        Location::factory()->create(['org_id' => $this->orgB->id, 'name' => 'Site B1']);
    });

    $this->tenant = app(TenantContext::class);
});

it('ne montre aucune ligne tant qu\'aucune organisation n\'est sélectionnée (échec fermé)', function (): void {
    expect(Organization::query()->count())->toBe(0)
        ->and(Location::query()->count())->toBe(0);
});

it('ne montre que les données de l\'organisation courante', function (): void {
    $this->tenant->set($this->orgA->id);

    expect(Organization::query()->pluck('id')->all())->toBe([$this->orgA->id])
        ->and(Location::query()->pluck('name')->sort()->values()->all())->toBe(['Site A1', 'Site A2']);

    $this->tenant->set($this->orgB->id);

    expect(Location::query()->pluck('name')->all())->toBe(['Site B1']);
});

it('interdit d\'écrire une ligne pour une autre organisation', function (): void {
    $this->tenant->set($this->orgA->id);

    Location::query()->create(['org_id' => $this->orgB->id, 'name' => 'Intrus']);
})->throws(QueryException::class, 'row-level security');

it('autorise d\'écrire dans sa propre organisation', function (): void {
    $this->tenant->set($this->orgA->id);

    Location::query()->create(['org_id' => $this->orgA->id, 'name' => 'Site A3']);

    expect(Location::query()->count())->toBe(3);
});

it('ne peut ni modifier ni supprimer les lignes d\'une autre organisation', function (): void {
    $this->tenant->set($this->orgA->id);

    $updated = DB::table('locations')->where('org_id', $this->orgB->id)->update(['name' => 'Piraté']);
    $deleted = DB::table('locations')->where('org_id', $this->orgB->id)->delete();

    expect($updated)->toBe(0)->and($deleted)->toBe(0);

    $name = $this->asOwner(fn () => Location::query()->where('org_id', $this->orgB->id)->value('name'));
    expect($name)->toBe('Site B1');
});

it('ne permet pas de « changer » d\'organisation en modifiant org_id', function (): void {
    $this->tenant->set($this->orgA->id);
    $locationId = Location::query()->where('name', 'Site A1')->value('id');

    DB::table('locations')->where('id', $locationId)->update(['org_id' => $this->orgB->id]);
})->throws(QueryException::class, 'row-level security');

it('n\'autorise la création d\'une organisation qu\'avec son propre identifiant comme contexte (inscription)', function (): void {
    $newId = (string) Str::uuid7();
    $this->tenant->set($newId);

    Organization::query()->create(['id' => $newId, 'name' => 'Nouveau', 'slug' => 'nouveau-'.Str::random(4)]);

    expect(Organization::query()->find($newId))->not->toBeNull();
});

it('refuse la création d\'une organisation sous un autre contexte', function (): void {
    $this->tenant->set($this->orgA->id);

    Organization::query()->create(['id' => (string) Str::uuid7(), 'name' => 'Autre', 'slug' => 'autre-'.Str::random(4)]);
})->throws(QueryException::class, 'row-level security');

it('rend le contexte lorsque run() se termine, même en cas d\'exception', function (): void {
    $this->tenant->set($this->orgA->id);

    try {
        $this->tenant->run($this->orgB->id, function (): void {
            expect(Location::query()->pluck('name')->all())->toBe(['Site B1']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // attendu
    }

    expect($this->tenant->current())->toBe($this->orgA->id);
});

it('rejette un identifiant d\'organisation invalide', function (): void {
    $this->tenant->set("x'; DROP TABLE organizations; --");
})->throws(InvalidArgumentException::class);

it('liste les rattachements d\'un utilisateur avant tout contexte, sans exposer les autres', function (): void {
    [$user, $other] = $this->asOwner(function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        OrgMember::factory()->owner()->create(['org_id' => $this->orgA->id, 'user_id' => $user->id]);
        OrgMember::factory()->create(['org_id' => $this->orgB->id, 'user_id' => $user->id, 'role' => 'practitioner']);
        OrgMember::factory()->owner()->create(['org_id' => $this->orgB->id, 'user_id' => $other->id]);

        return [$user, $other];
    });

    // Sans contexte, la table est illisible directement...
    expect(OrgMember::query()->count())->toBe(0);

    // ... mais la fonction dédiée renvoie exactement les rattachements de cet utilisateur.
    $rows = collect(DB::select('select * from auth_memberships(?)', [$user->id]))
        ->pluck('role', 'org_id')->all();

    expect($rows)->toBe([$this->orgA->id => 'owner', $this->orgB->id => 'practitioner'])
        ->and(DB::select('select * from auth_memberships(?)', [$other->id]))->toHaveCount(1);
});

it('n\'autorise qu\'un seul propriétaire actif par organisation', function (): void {
    $this->asOwner(function (): void {
        OrgMember::factory()->owner()->create(['org_id' => $this->orgA->id]);
        OrgMember::factory()->owner()->create(['org_id' => $this->orgA->id]);
    });
})->throws(QueryException::class, 'org_members_one_owner');

it('ne permet qu\'un seul site par défaut par organisation', function (): void {
    $this->asOwner(function (): void {
        Location::factory()->create(['org_id' => $this->orgA->id, 'is_default' => true]);
        Location::factory()->create(['org_id' => $this->orgA->id, 'is_default' => true]);
    });
})->throws(QueryException::class, 'locations_one_default_per_org');

it('rejette un statut d\'organisation inconnu', function (): void {
    $this->asOwner(fn () => Organization::factory()->create(['status' => 'inconnu']));
})->throws(QueryException::class, 'organizations_status_check');

it('accepte le statut « paused » ajouté par les flowcharts', function (): void {
    $org = $this->asOwner(fn () => Organization::factory()->status(Organization::STATUS_PAUSED)->create());

    expect($org->status)->toBe('paused');
});

it('rend le compte en lecture seule quand il est suspendu ou en suppression', function (): void {
    expect(Organization::factory()->status('suspended')->make()->isReadOnly())->toBeTrue()
        ->and(Organization::factory()->status('pending_deletion')->make()->isReadOnly())->toBeTrue()
        ->and(Organization::factory()->status('active')->make()->isReadOnly())->toBeFalse()
        ->and(Organization::factory()->status('paused')->make()->isReadOnly())->toBeFalse();
});

describe('journal d\'audit (ajout seul)', function (): void {
    it('enregistre une action pour l\'organisation courante', function (): void {
        $this->tenant->set($this->orgA->id);

        Audit::record('org.settings.updated', 'user', orgId: $this->orgA->id, diff: ['name' => ['Ancien', 'Nouveau']]);

        expect(DB::table('audit_logs')->where('org_id', $this->orgA->id)->count())->toBe(1);
    });

    it('interdit d\'écrire un événement pour une autre organisation', function (): void {
        $this->tenant->set($this->orgA->id);

        Audit::record('org.settings.updated', 'user', orgId: $this->orgB->id);
    })->throws(QueryException::class, 'row-level security');

    it('interdit toute modification ou suppression par le rôle applicatif', function (): void {
        $this->tenant->set($this->orgA->id);
        Audit::record('org.created', 'user', orgId: $this->orgA->id);

        expect(fn () => DB::table('audit_logs')->update(['action' => 'falsifié']))->toThrow(QueryException::class, 'permission denied')
            ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class, 'permission denied');
    });

    it('interdit aussi la modification par le propriétaire (trigger)', function (): void {
        $this->tenant->set($this->orgA->id);
        Audit::record('org.created', 'user', orgId: $this->orgA->id);

        $this->asOwner(fn () => DB::table('audit_logs')->update(['action' => 'falsifié']));
    })->throws(QueryException::class, 'ajout seul');

    it('ne montre à un client que ses propres événements', function (): void {
        $this->tenant->set($this->orgA->id);
        Audit::record('a', 'user', orgId: $this->orgA->id);
        $this->tenant->set($this->orgB->id);
        Audit::record('b', 'user', orgId: $this->orgB->id);

        expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['b']);
    });
});

describe('rôle Super Admin (pgsql_platform)', function (): void {
    it('lit toutes les organisations mais ne peut pas écrire dans les données métier', function (): void {
        $platform = DB::connection('pgsql_platform');

        expect($platform->table('organizations')->count())->toBe(2)
            ->and($platform->table('locations')->count())->toBe(3)
            ->and(fn () => $platform->table('locations')->delete())->toThrow(QueryException::class, 'permission denied');
    });

    it('peut changer le statut d\'une organisation (suspension)', function (): void {
        $platform = DB::connection('pgsql_platform');

        $platform->table('organizations')->where('id', $this->orgA->id)->update(['status' => 'suspended']);

        expect($platform->table('organizations')->where('id', $this->orgA->id)->value('status'))->toBe('suspended');
    });

    it('est le seul à accéder aux comptes Super Admin', function (): void {
        $this->asOwner(fn () => DB::table('platform_admins')->insert([
            'id' => (string) Str::uuid7(), 'email' => 'fondateur@rendivoo.test', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]));

        expect(DB::connection('pgsql_platform')->table('platform_admins')->count())->toBe(1)
            ->and(fn () => DB::table('platform_admins')->count())->toThrow(QueryException::class, 'permission denied');
    });
});
