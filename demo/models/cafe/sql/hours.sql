SELECT day, time, state FROM hoursdata WHERE (enabled IS NULL OR enabled != '0') ORDER BY id
