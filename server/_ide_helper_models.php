<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * One entry in the append-only audit log.
 *
 * The database enforces immutability with a withheld grant and a trigger; the
 * overrides below only fail earlier and more legibly than a raw SQLSTATE 45000
 * arriving from three layers down.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_role
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<array-key, mixed>|null $before
 * @property array<array-key, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Models\User|null $actor
 * @property-read \App\Models\Tenant|null $estate
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereActorRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditEntry whereUserAgent($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAuditEntry {}
}

namespace App\Models{
/**
 * A panic, duress, medical, fire or intrusion alert.
 *
 * Raised on a mobile device and consumed by the Gemini Console. Central,
 * because one dispatcher watches every estate at once.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $kind
 * @property int|null $guard_id
 * @property string|null $raised_by_name
 * @property string|null $unit_reference
 * @property string $status
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property \Illuminate\Support\Carbon|null $device_time
 * @property \Illuminate\Support\Carbon $server_time
 * @property bool $clock_skewed
 * @property bool $captured_offline
 * @property string|null $idempotency_key
 * @property int|null $acknowledged_by
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property bool $is_simulated
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \App\Models\Guard|null $raisedByGuard
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereAcknowledgedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereAcknowledgedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereCapturedOffline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereClockSkewed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereDeviceTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereGuardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereIsSimulated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereRaisedByName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereResolutionNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereServerTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereUnitReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DuressAlert whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperDuressAlert {}
}

namespace App\Models{
/**
 * A user's right to reach one estate, in one role.
 *
 * Central, like everything about identity. An estate database holds residents
 * and money; it never holds the answer to "may this person be here".
 *
 * @property int $id
 * @property int $user_id
 * @property string $tenant_id
 * @property int $role_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Role $role
 * @property-read \App\Models\Tenant|null $tenant
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EstateAssignment whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperEstateAssignment {}
}

namespace App\Models\Estate{
/**
 * A charge raised against a household.
 *
 * Lives only in gs_estate_<subdomain>. There is no tenant_id column: the
 * database boundary is the tenant boundary, and a second source of truth
 * could only ever disagree with the first.
 *
 * No model in this namespace declares a connection. Under
 * DatabaseTenancyBootstrapper the default connection IS the current estate,
 * so a query with no tenant context fails to resolve rather than silently
 * reading central data.
 *
 * @property \Brick\Money\Money $amount
 * @property-read \App\Models\Estate\Household|null $household
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Charge query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperCharge {}
}

namespace App\Models\Estate{
/**
 * A household occupying a unit. Estate database only.
 *
 * `access_restricted` is the ONLY thing about a household's standing that a
 * guard is ever told — a boolean, never an amount, never an ageing bucket,
 * never a payment history (invariant 2).
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Estate\Charge> $charges
 * @property-read int|null $charges_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Estate\Resident> $residents
 * @property-read int|null $residents_count
 * @property-read \App\Models\Estate\Unit|null $unit
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperHousehold {}
}

namespace App\Models\Estate{
/**
 * A posted journal entry. APPEND-ONLY.
 *
 * Enforced at the database in two independent layers — a withheld grant and a
 * BEFORE UPDATE/DELETE trigger — so this class does not carry the guarantee.
 * The overrides below exist to fail early and legibly, with an explanation, in
 * place of a raw SQLSTATE 45000 surfacing from three layers down.
 *
 * A correction is a new entry referencing the original, via reverse().
 *
 * @property \Brick\Money\Money $amount
 * @property-read Journal|null $reverses
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperJournal {}
}

namespace App\Models\Estate{
/**
 * A person in a household. Estate database only.
 *
 * `user_id` points at the central users table and is deliberately not a
 * foreign key: it crosses a database boundary, which MySQL cannot constrain.
 * Integrity is the service layer's job.
 *
 * @property-read \App\Models\Estate\Household|null $household
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperResident {}
}

namespace App\Models\Estate{
/**
 * A physical address within one estate. Estate database only.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Estate\Household> $households
 * @property-read int|null $households_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUnit {}
}

namespace App\Models{
/**
 * A security officer employed by Gemini Security Limited.
 *
 * Central, never per estate: a guard is posted at an estate and can be
 * reassigned, and the cross-client roster has to see all of them at once.
 *
 * @property int $id
 * @property string $full_name
 * @property string $employee_number
 * @property string $psra_number
 * @property \Illuminate\Support\Carbon|null $psra_expires_on
 * @property string $employment_type
 * @property string $status
 * @property string|null $phone
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $hired_on
 * @property string|null $tenant_id
 * @property int|null $post_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \App\Models\Post|null $post
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard needingCompliance()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereEmployeeNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereEmploymentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereFullName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereHiredOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard wherePostId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard wherePsraExpiresOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard wherePsraNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guard whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGuard {}
}

namespace App\Models{
/**
 * An invoice Gemini Security raises against a client estate.
 *
 * Not to be confused with a resident charge, which lives in the estate
 * database. These are two separate ledgers and the distinction is load-bearing:
 * an overdue platform invoice must never restrict a resident.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $subscription_id
 * @property string $reference
 * @property string $period
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $total_minor
 * @property string $currency
 * @property \Illuminate\Support\Carbon $due_on
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $paid_on
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Brick\Money\Money $total
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InvoiceLine> $lines
 * @property-read int|null $lines_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDueOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePaidOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTotalMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperInvoice {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property string $currency
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Brick\Money\Money $total
 * @property-read \App\Models\Invoice $invoice
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereInvoiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereTotalMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereUnitPriceMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperInvoiceLine {}
}

namespace App\Models{
/**
 * A navigable module in one of the two consoles.
 *
 * Modules live centrally with the role matrix, never per estate, so that two
 * estates cannot drift into different definitions of the same module.
 *
 * @property int $id
 * @property string $key
 * @property \App\Enums\Console $console
 * @property string $label
 * @property string|null $section
 * @property int $sort
 * @property bool $is_locked_financial
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereIsLockedFinancial($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperModule {}
}

namespace App\Models{
/**
 * One payroll run. APPEND-ONLY once approved (invariant 4).
 *
 * A correction to an approved run is a new adjustment run referencing it,
 * never an edit.
 *
 * @property int $id
 * @property string $reference
 * @property string $period_label
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property int $periods_per_year
 * @property int $statutory_rate_version_id
 * @property string $status
 * @property int $gross_minor
 * @property int $net_minor
 * @property string $currency
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payslip> $payslips
 * @property-read int|null $payslips_count
 * @property-read \App\Models\StatutoryRateVersion $rateVersion
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereGrossMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereNetMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodsPerYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereStatutoryRateVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPayrollRun {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $guard_id
 * @property string|null $tenant_id
 * @property int $gross_minor
 * @property int $nis_minor
 * @property int $nht_minor
 * @property int $education_tax_minor
 * @property int $paye_minor
 * @property int $net_minor
 * @property string $currency
 * @property string|null $paye_note
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Guard $employee
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \App\Models\PayrollRun $run
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereEducationTaxMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereGrossMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereGuardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNetMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNhtMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereNisMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayeMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayeNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip wherePayrollRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payslip whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPayslip {}
}

namespace App\Models{
/**
 * Permissions live in gs_platform, never per estate.
 *
 * See App\Models\Role for why this pinning is required rather than optional.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission> $teams
 * @property-read int|null $teams_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission team($teams, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission withoutRole($roles, ?string $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission withoutTeam($teams)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPermission {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $price_per_unit_minor
 * @property string $currency
 * @property int $min_units
 * @property bool $is_active
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Brick\Money\Money $price_per_unit
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Subscription> $subscriptions
 * @property-read int|null $subscriptions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereMinUnits($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan wherePricePerUnitMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Plan whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlan {}
}

namespace App\Models{
/**
 * A guarded position at an estate: a gate, a patrol route, a relief slot.
 *
 * Central alongside guards, because rostering is a Gemini operation spanning
 * every client rather than something each estate manages for itself.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $name
 * @property string $type
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Guard> $guards
 * @property-read int|null $guards_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Post whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPost {}
}

namespace App\Models{
/**
 * Roles live in gs_platform, never per estate.
 *
 * spatie/laravel-permission has no configuration key for the connection, so
 * its stock models resolve against Laravel's default connection. Under
 * DatabaseTenancyBootstrapper that default is swapped to the tenant database
 * for the duration of a tenant request, which would silently give every estate
 * its own private copy of the role table.
 *
 * The consequences are not cosmetic: the role-permission matrix drives runtime
 * navigation generation, so per-estate role tables would let two estates drift
 * into different definitions of what a "Treasurer" may see. Pinning to the
 * central connection keeps one authoritative matrix.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property \App\Enums\Console $console
 * @property string|null $label
 * @property int $sort
 * @property \App\Enums\AccessScope $scope_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RoleModuleAccess> $moduleAccess
 * @property-read int|null $module_access_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Module> $modules
 * @property-read int|null $modules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereScopeDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRole {}
}

namespace App\Models{
/**
 * One cell of a role access matrix.
 *
 * Three orthogonal facts, not one enum (D-007): what may be done (level),
 * whether the irreversible act may be committed (can_approve), and which
 * records are in reach (scope).
 *
 * @property int $id
 * @property int $role_id
 * @property int $module_id
 * @property \App\Enums\AccessLevel $level
 * @property bool $can_approve
 * @property \App\Enums\AccessScope $scope
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Module $module
 * @property-read \App\Models\Role $role
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCanApprove($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereModuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRoleModuleAccess {}
}

namespace App\Models{
/**
 * A dated set of statutory rates.
 *
 * Versioned data with effective dates, never constants. A payroll run records
 * the version it used so it reproduces exactly, to the cent, years later.
 *
 * Rates are basis points: 3% is 300. No float ever touches a wage.
 *
 * @property int $id
 * @property string $label
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property int $nis_employee_bp
 * @property int $nis_employer_bp
 * @property int $nis_ceiling_annual_minor
 * @property int $nht_employee_bp
 * @property int $nht_employer_bp
 * @property int $education_tax_employee_bp
 * @property int $education_tax_employer_bp
 * @property int $paye_bp
 * @property int $paye_threshold_annual_minor
 * @property bool $is_verified
 * @property string|null $verified_by
 * @property \Illuminate\Support\Carbon|null $verified_on
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEducationTaxEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEducationTaxEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEffectiveFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereEffectiveTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereIsVerified($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNhtEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNhtEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisCeilingAnnualMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisEmployeeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereNisEmployerBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion wherePayeBp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion wherePayeThresholdAnnualMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereVerifiedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion whereVerifiedOn($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperStatutoryRateVersion {}
}

namespace App\Models{
/**
 * An estate's subscription to GeminiSecure.
 *
 * `dunning` and `suspended` gate BILLING features only. Neither ever restricts
 * entry, a safety function, or a resident. Access is never withheld over a
 * billing dispute.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $plan_id
 * @property int $unit_count
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $started_on
 * @property \Illuminate\Support\Carbon|null $renews_on
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Tenant|null $estate
 * @property-read \App\Models\Plan $plan
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription wherePlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereRenewsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStartedOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUnitCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subscription whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSubscription {}
}

namespace App\Models{
/**
 * An estate.
 *
 * Each one owns a physically separate database, `gs_estate_<subdomain>`, with
 * its own MySQL user. A leak between two communities is therefore a connection
 * error rather than a query error, which is much harder to cause by accident
 * than a forgotten WHERE clause.
 *
 * The tenant id is the subdomain, so stancl's `prefix + id` yields the database
 * name directly with nothing to keep in sync.
 *
 * @property string $id
 * @property string $name
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $provisioned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $data
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Stancl\Tenancy\Database\Models\Domain> $domains
 * @property-read int|null $domains_count
 * @property-read string $subdomain
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> all($columns = ['*'])
 * @method static \Stancl\Tenancy\Database\TenantCollection<int, static> get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereProvisionedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTenant {}
}

namespace App\Models{
/**
 * A user account, always central.
 *
 * Accounts are issued, never self-created — there is no public registration
 * endpoint. A user belongs to exactly one console and never both.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \App\Enums\Console $console
 * @property string $status
 * @property string|null $phone
 * @property string|null $mfa_secret
 * @property bool $mfa_enabled
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EstateAssignment> $assignments
 * @property-read int|null $assignments_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Permission> $teams
 * @property-read int|null $teams_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User team($teams, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTeam($teams)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}

