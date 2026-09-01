vcl 4.1;

import std;

# Magento nginx backend
backend default {
    .host = "nginx";
    .port = "80";
    .first_byte_timeout = 600s;
    .between_bytes_timeout = 600s;
    .connect_timeout = 5s;
}

# ACL for cache purge requests — only allow from php-fpm and localhost
acl purge {
    "localhost";
    "127.0.0.1";
    "php-fpm";
}

sub vcl_recv {
    # Forward real client IP
    if (req.restarts == 0) {
        if (req.http.X-Forwarded-For) {
            set req.http.X-Forwarded-For = req.http.X-Forwarded-For + ", " + client.ip;
        } else {
            set req.http.X-Forwarded-For = client.ip;
        }
    }

    # Handle PURGE requests from Magento
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "Method Not Allowed"));
        }
        return (purge);
    }

    # Handle BAN requests (tag-based invalidation)
    if (req.method == "BAN") {
        if (!client.ip ~ purge) {
            return (synth(405, "Method Not Allowed"));
        }
        if (req.http.X-Magento-Tags-Pattern) {
            ban("obj.http.X-Magento-Tags ~ " + req.http.X-Magento-Tags-Pattern);
        }
        return (synth(200, "Ban added"));
    }

    # Only cache GET and HEAD
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # Never cache admin, REST API, or GraphQL — pass directly to backend
    if (req.url ~ "^/d2_Dmin" || req.url ~ "^/rest/" || req.url ~ "^/graphql") {
        return (pass);
    }

    # Pass if customer is logged in or has items in cart
    if (req.http.cookie ~ "PHPSESSID=" ||
        req.http.cookie ~ "customer" ||
        req.http.cookie ~ "cart") {
        return (pass);
    }

    # Remove tracking and utm parameters to improve cache hit rate
    set req.url = regsuball(req.url, "(\?|&)(utm_source|utm_medium|utm_campaign|utm_content|utm_term|gclid|fbclid)=[^&]+", "");
    set req.url = regsub(req.url, "\?&", "?");
    set req.url = regsub(req.url, "\?$", "");

    # Pass health check
    if (req.url == "/health_check.php") {
        return (pass);
    }

    # Strip cookies that Magento does not use for caching
    set req.http.cookie = regsuball(req.http.cookie, ";?\s*(XDEBUG_SESSION|has_js)=[^;]+", "");
    set req.http.cookie = regsuball(req.http.cookie, ";\s*$", "");

    if (req.http.cookie == "") {
        unset req.http.cookie;
    }

    return (hash);
}

sub vcl_hash {
    hash_data(req.url);

    if (req.http.host) {
        hash_data(req.http.host);
    } else {
        hash_data(server.ip);
    }

    # Separate cache for HTTPS
    if (req.http.X-Forwarded-Proto) {
        hash_data(req.http.X-Forwarded-Proto);
    }

    return (lookup);
}

sub vcl_backend_response {
    # Store Magento cache tags for BAN-based invalidation
    if (beresp.http.X-Magento-Tags) {
        set beresp.http.X-Magento-Tags = beresp.http.X-Magento-Tags;
    }

    # Cache successful responses
    if (beresp.status == 200 || beresp.status == 301 || beresp.status == 302) {
        set beresp.grace = 30s;
    }

    # Don't cache error responses
    if (beresp.status >= 400) {
        set beresp.uncacheable = true;
        return (deliver);
    }

    # Strip Set-Cookie only for genuinely cacheable public responses (static assets).
    # Magento's dynamic pages always have no-store/no-cache; stripping their cookies
    # breaks PHPSESSID delivery and causes the cart to appear empty after add-to-cart.
    if (!beresp.uncacheable &&
        beresp.http.Cache-Control !~ "no-store" &&
        beresp.http.Cache-Control !~ "no-cache" &&
        beresp.http.Cache-Control !~ "private" &&
        bereq.url !~ "^/d2_Dmin" &&
        bereq.url !~ "^/rest/" &&
        bereq.url !~ "^/graphql") {
        unset beresp.http.Set-Cookie;
    }

    return (deliver);
}

sub vcl_deliver {
    # Debug header — shows HIT or MISS
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
        set resp.http.X-Cache-Hits = obj.hits;
    } else {
        set resp.http.X-Cache = "MISS";
    }

    # Remove internal headers before sending to client
    unset resp.http.X-Magento-Tags;
    unset resp.http.X-Powered-By;
    unset resp.http.Server;

    return (deliver);
}
