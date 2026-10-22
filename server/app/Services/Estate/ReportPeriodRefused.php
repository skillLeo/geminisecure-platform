<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/**
 * A period a report cannot be run over, and the sentence that says why (12 §1).
 *
 * A DIFFERENT REFUSAL FROM "THERE IS NO SUCH REPORT", and the screen treats it
 * differently. A report this estate cannot run sends the reader back to the
 * catalogue; a period it cannot run over keeps them on the report they were
 * reading, because the report is fine and only the dates need choosing again.
 *
 * NEVER A SILENT CORRECTION. February 31 is not read as March 3 and month 13
 * is not rounded into next year: a report states its period, and a period the
 * reader did not choose would be a report that looked asked-for and was not.
 */
final class ReportPeriodRefused extends DomainException {}
