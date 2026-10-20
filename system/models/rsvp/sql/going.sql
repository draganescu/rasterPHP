SELECT COALESCE(SUM(people), 0) AS going FROM rsvpdata WHERE event = ? AND status = 'going' AND id != ?
