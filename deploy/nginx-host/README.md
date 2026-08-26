# Server-level nginx (host machine, outside Docker)

These files live on the VPS at `/etc/nginx/`, **not** inside Docker.

## Role

- Terminates SSL (your certificates, managed however you like: manual, Certbot, external CA)
- Proxies all traffic to `127.0.0.1:6081` (Docker Varnish, localhost-only binding)
- Sets headers so Magento and Varnish know the real client IP and that the connection is HTTPS
- Routes multiple domains to the same Docker stack (Magento multi-store)

## File layout

```
/etc/nginx/
├── nginx.conf                    (include sites-enabled)
├── snippets/
│   ├── ssl-params.snippet        (TLS settings shared across all vhosts)
│   └── magento-proxy.snippet     (proxy_pass block shared across all vhosts)
└── sites-enabled/
    ├── store1.example.com.conf   (one file per domain)
    └── store2.example.com.conf
```

## Quick install

```bash
# Copy snippets
cp snippets/*.snippet /etc/nginx/snippets/

# Copy and customise a vhost for each domain
cp sites-enabled/store.conf.example /etc/nginx/sites-enabled/yourdomain.com.conf
# Edit: server_name, ssl_certificate, ssl_certificate_key

nginx -t && systemctl reload nginx
```
