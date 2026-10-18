SELECT COUNT(*) AS total FROM bookingdata WHERE service = ? AND `date` >= ? AND status IN ('requested', 'confirmed')
