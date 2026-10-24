SELECT day, time, state FROM hoursdata WHERE (enabled IS NULL OR enabled != '0') AND (published_at IS NULL OR published_at <= :now) ORDER BY id
