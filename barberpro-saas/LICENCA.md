# Sistema de Licença BarberPro

## Para o desenvolvedor (você)

### 1. Configure o segredo no wp-config.php do SEU servidor (onde você gera as chaves)

```php
// Adicione no wp-config.php — use uma string longa e aleatória, só sua
define( 'BARBERPRO_LICENSE_SECRET', 'minha-chave-super-secreta-aqui-abc123xyz' );
```

### 2. Acesse o Gerador de Chaves

No WordPress onde o secret está configurado, acesse:
```
/wp-admin/admin.php?page=barberpro_license&barberpro_keygen=1
```

Preencha:
- **Domínio do cliente**: `barbearia.com.br`
- **Dias de validade**: `30`, `90`, `365`, etc.
- **Plano**: Trial / Basic / Pro / Lifetime

A chave gerada tem este formato:
```
BP2-eyJkIjoiYmFyYmVhcmlhLmNvbS5iciIsImUiOiIyMDI1LTA2LTMwIiwicCI6InBybyIsImkiOjE3MDAwMDAwMDB9-A1B2C3D4
```

### 3. Entregue a chave ao cliente junto com o plugin

No plugin do cliente, o wp-config.php **NÃO precisa** ter o secret.
A verificação é feita apenas com a chave — ela já carrega tudo embutido.

---

## Para o cliente (instalação)

1. Instale o plugin normalmente no WordPress
2. Acesse **BarberPro → 🔑 Licença**
3. Cole a chave recebida
4. Clique em **Ativar Licença**

---

## Comportamento quando a licença expira

| Situação | O que acontece |
|---|---|
| Licença ativa | Plugin funciona normalmente |
| Faltam ≤ 7 dias | Aviso amarelo no admin |
| Expirada | Admin bloqueado + shortcodes mostram mensagem discreta |
| Domínio diferente | Bloqueio imediato |
| localhost/127.0.0.1 | Verificação de domínio ignorada (desenvolvimento) |

---

## Segurança

- A chave usa **HMAC-SHA256** — impossível de forjar sem o secret
- Verificação é **100% local** — sem chamada de rede
- O secret nunca vai para o client — está só no seu servidor
