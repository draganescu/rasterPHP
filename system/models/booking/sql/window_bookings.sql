SELECT `date`, resource, `time`, ends, people FROM bookingdata WHERE `date` >= ? AND `date` <= ? AND status IN ('requested', 'confirmed') ORDER BY `date`, `time`, id
