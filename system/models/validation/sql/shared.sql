SELECT COUNT(DISTINCT hash) AS emails FROM formhit WHERE form = ? AND kind = 'pair' AND hash LIKE ? AND at >= ?
