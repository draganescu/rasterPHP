SELECT COALESCE(MAX(busy.people), 0) AS people, COALESCE(MAX(busy.bookings), 0) AS bookings
FROM (
	SELECT points.at, COALESCE(SUM(b.people), 0) AS people, COUNT(b.id) AS bookings
	FROM (
		SELECT (? + 0) AS at
		UNION
		SELECT (substr(`time`, 1, 2) * 60 + substr(`time`, 4, 2)) AS at
		FROM bookingdata
		WHERE `date` = ? AND resource = ? AND id != ? AND status IN ('requested', 'confirmed')
			AND (substr(`time`, 1, 2) * 60 + substr(`time`, 4, 2)) > (? + 0)
			AND (substr(`time`, 1, 2) * 60 + substr(`time`, 4, 2)) < (? + 0)
	) points
	LEFT JOIN bookingdata b ON b.`date` = ? AND b.resource = ? AND b.id != ? AND b.status IN ('requested', 'confirmed')
		AND (substr(b.`time`, 1, 2) * 60 + substr(b.`time`, 4, 2)) <= points.at
		AND points.at < (CASE WHEN (substr(b.ends, 1, 2) * 60 + substr(b.ends, 4, 2)) = 0 THEN 1440 ELSE (substr(b.ends, 1, 2) * 60 + substr(b.ends, 4, 2)) END)
	GROUP BY points.at
) busy
