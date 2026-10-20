SELECT event, status, COUNT(*) AS replies, COALESCE(SUM(people), 0) AS people FROM rsvpdata WHERE event >= ? AND event <= ? GROUP BY event, status
