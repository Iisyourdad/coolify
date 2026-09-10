# Remote server forwarding

When a team selects a master domain router, its public address is the ingress point for remote Coolify resources. HTTP(S) reaches the master's Traefik proxy, which owns public certificates and forwards traffic over the target server's configured tunnel/private address. Published TCP and UDP ports use isolated, feature-owned Nginx stream containers on the master.

For example, a Java Minecraft mapping of `25565:25565/tcp` accepts traffic at `MASTER_IP:25565` and proxies it to the remote server's configured reachable address on port `25565`. A Bedrock mapping of `19132:19132/udp` works equivalently for UDP. The backend sees the master/tunnel source address; Coolify does not enable PROXY protocol because arbitrary protocols, including Minecraft, may not support it.

Coolify can create the master-side listener only. Configure cloud security groups, router/NAT rules, and DNS to send the required public traffic to the master. Normal proxied HTTP CDN/DNS services do not automatically carry arbitrary TCP or UDP game ports. Changing masters does not move a public IP, NAT rule, Cloudflare record, or external DNS record; update those external systems to the new master.

Only explicit published mappings are forwarded. TCP and UDP ownership is separate, so `25565/tcp` and `25565/udp` may coexist. TCP 80/443 and Traefik's UDP 443 are reserved. A port collision never replaces an existing listener.
