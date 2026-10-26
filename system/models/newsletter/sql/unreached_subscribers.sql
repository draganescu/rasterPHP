SELECT id, email, name, token FROM subscriber WHERE status = 'confirmed' AND email NOT IN (SELECT email FROM newsletterdelivery WHERE issue = ?) ORDER BY id
