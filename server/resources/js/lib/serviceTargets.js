/**
 * How long a maintenance service target is, said the way an office says it (M98): "a day", "3 days", "an hour",
 * "36 hours". A target is whole hours (Maintenance::setTargets); whole days are said in days.
 *
 * @param {number} hours
 * @returns {string}
 */
export const spellHours = (hours) => {
    const value = Number(hours)

    if (!Number.isInteger(value) || value < 1) {
        return ''
    }

    if (value % 24 === 0) {
        const days = value / 24

        return days === 1 ? 'a day' : `${days} days`
    }

    return value === 1 ? 'an hour' : `${value} hours`
}

/** The ladder every estate has until it sets its own (Maintenance::SLA_HOURS). */
export const DEFAULT_TARGETS = Object.freeze({ high: 24, medium: 72, low: 168 })
