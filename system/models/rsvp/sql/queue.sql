SELECT id, people, waiting_since FROM rsvpdata WHERE event = ? AND status = 'waiting' ORDER BY waiting_since, id
