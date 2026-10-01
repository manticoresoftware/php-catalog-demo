# Optional: keep Docker Compose running across reboots

Compose already sets `restart: unless-stopped` on both services. After
`docker compose up -d` once, Docker will restart the stack when the daemon
comes back (e.g. after a host reboot), as long as Docker itself is enabled:

```bash
sudo systemctl enable --now docker
cd /opt/php-catalog-demo   # or your checkout path
docker compose up -d
```

If you still want a systemd unit that brings the stack up on boot:

```ini
[Unit]
Description=Manticore PHP Catalog Demo
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/opt/php-catalog-demo
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down

[Install]
WantedBy=multi-user.target
```

Save as `/etc/systemd/system/php-catalog-demo.service`, then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now php-catalog-demo
```

Adjust `WorkingDirectory` to the real repo path on the server.
