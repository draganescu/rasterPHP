SELECT id, `time`, ends, people FROM bookingdata WHERE `date` = ? AND resource = ? AND status IN ('requested', 'confirmed') ORDER BY `time`, id
