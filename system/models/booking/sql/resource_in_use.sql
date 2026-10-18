SELECT COUNT(*) AS total FROM bookingdata WHERE resource = ? AND `date` >= ? AND status IN ('requested', 'confirmed')
