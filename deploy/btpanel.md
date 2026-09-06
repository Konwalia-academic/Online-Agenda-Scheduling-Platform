# 宝塔面板 (BTPanel) 部署指南 — Agenda Platform

This guide shows how to deploy this project on a Linux server using the **宝塔面板 / BTPanel** (www.bt.cn). It is a friendlier alternative to the manual Nginx steps in `readme.md` §5. 以下步骤假设你的面板已经是正式版并绑定了域名（如 `agendas.limengjia.cn`）。

> 重要：宝塔默认自带 **防跨站攻击 (open_basedir)**，会把 PHP 限制在站点目录内。本项目把程序放在站点根目录、把可访问入口放在 `public/` 子目录，所以 **必须把防跨站攻击范围设为站点根目录（或关闭）**，而不能设置为 `/public`——否则 PHP 无法读取 `../../app/` 下的核心文件。详见第 4 步。

---

## 0. 准备 / Prerequisites

- 已安装并登录宝塔面板 (BTPanel)
- 域名已解析到服务器 IP（用于站点创建与 HTTPS）
- 服务器已安装 Nginx、MySQL、PHP（见第 1 步）

---

## 1. 安装软件 / Install the stack

打开宝塔 **软件商店 (Software Store)**，安装：

| 软件 | 说明 |
|---|---|
| Nginx | Web 服务器 |
| MySQL 5.7 或 8.0 | 数据库（MariaDB 亦可） |
| PHP 8.2（或 8.0/8.1） | 运行环境。安装时/安装后在 PHP 设置中启用扩展：`pdo_mysql`、`curl`、`openssl`、`mbstring`、`fileinfo` |

> 勾选扩展的方法：软件商店 → PHP → 设置 → 安装扩展，勾选上述扩展后保存。`pdo_mysql`、`curl`、`openssl`、`mbstring` 通常默认开启。

---

## 2. 创建站点 / Create the site

1. 左侧菜单 **网站 → 添加站点 (Add site)**
2. 域名：`agendas.limengjia.cn`（换成你的域名）
3. 根目录默认：`/www/wwwroot/agendas.limengjia.cn`
4. PHP 版本：选择 **PHP 8.2**
5. 数据库：先不创建（第 4 步手动创建更清晰），或直接勾选创建也可以
6. 提交创建

创建后**不要急着访问**，先继续下面的配置。

---

## 3. 上传项目并设置运行目录 / Upload & set run directory

**上传：** 将本项目所有文件上传到站点根目录 `/www/wwwroot/agendas.limengjia.cn/`（直接放在根目录，使根目录下包含 `public/`、`app/`、`storage/` 等）。

> 本项目结构：
> ```
> /www/wwwroot/agendas.limengjia.cn/
> ├── public/     ← 对外可访问的入口（index.php, book.php, admin/ …）
> ├── app/        ← 核心代码（不可直接访问）
> ├── i18n/ scripts/ deploy/ storage/
> └── readme.md
> ```

**设置运行目录（关键）：** 在 **网站 → 站点设置 → 网站目录 → 运行目录** 中，把运行目录从 `/` 改为 **`/public`**，然后保存。宝塔会为你生成对应的 Nginx 配置，使请求直接落在 `public/` 内的入口文件上。

**权限：** 网站 → 站点设置 → 文件权限（或直接命令行）：

```bash
chown -R www:www /www/wwwroot/agendas.limengjia.cn
chmod -R 775 /www/wwwroot/agendas.limengjia.cn/storage
```

必须保证 `www` 用户对 `storage/`（含子目录 uploads、cache、logs、tmp）可读写——日历缓存、上传的 .ics、日志都写在这里。

---

## 4. 防跨站攻击（open_basedir）— 必读 / Important

宝塔的 **网站 → 站点设置 → PHP → 防跨站攻击（open_basedir）** 默认会在项目目录前加上 `:/tmp/`。请确认：

- ❌ **不要**把 open_basedir 改成 `/www/wwwroot/agendas.limengjia.cn/public`
- ✅ 保持为站点根目录，例如：
  ```
  /www/wwwroot/agendas.limengjia.cn/:/tmp/
  ```
  （或直接关闭防跨站攻击）

原因：虽然运行目录是 `public/`，但所有入口文件都要 `require` 上级目录 `app/` 里的文件。若 open_basedir 只允许 `public/`，PHP 会报 `open_basedir restriction in effect`，后台/前台都会白屏或 500。

---

## 5. 创建数据库 / Create the database

1. 左侧菜单 **数据库 → 添加数据库**
2. 数据库名：`agenda`（可自定）
3. 用户名：`agenda`（与库名保持一致更清晰）
4. 密码：设置一个强密码并记下
5. 字符集：**utf8mb4**
6. 提交创建

> 数据库用户需要对 `agenda` 库有全部权限——宝塔默认给的库用户权限即可（SELECT/INSERT/UPDATE/DELETE/DDL）。

---

## 6. 运行安装向导 / Run the installer

浏览器打开：

```
https://agendas.limengjia.cn/install/
```

按向导填写：

| 字段 | 值 |
|---|---|
| 数据库主机 | `localhost` |
| 端口 | `3306` |
| 数据库名 | `agenda` |
| 数据库用户名/密码 | 第 5 步创建的用户/密码 |
| 管理员用户名/密码 | 你自定义（≥8 位） |
| 管理员邮箱 | 你的邮箱（接收预约申请通知） |
| 站点标题 / 基础地址 / 时区 | 按需填写 |

完成后会锁定安装程序。默认邀请码为 **`4310`**，登录后台后尽快修改。

> 若安装向导报“数据库错误”，通常是数据库账号权限不足或密码错误，回第 5 步检查即可。

---

## 7. 后台配置清单 / Post-install checklist

访问 `https://agendas.limengjia.cn/admin/` 登录后，按顺序配置：

1. **邮件设置 (E-mail)** — SMTP 主机/端口/加密/账号/密码（建议用应用专用密码），保存后点“发送测试邮件”验证。
2. **日历管理 (Calendars)** — 接入 Outlook（Graph）、Google（私有 iCal 地址）、上传 .ics，或添加 **CalDAV**（Nextcloud/Baikal/Radicale/iCloud），然后点“立即同步”。若没配日历，前台日历页会显示为空（属正常）。
3. **通用设置 (General settings)** — 工作时间、时段步长、时长范围、活动后缓冲、最大可提前天数、时区。
4. **外观设置 (Appearance)** — 修改全站主色。
5. **安全设置 (Security)** — 修改邀请码（默认 `4310`）、管理员用户名/密码。

---

## 8. 计划任务（自动同步）/ Cron job

宝塔左侧菜单 **计划任务 → 添加任务**：

- 任务类型：**Shell 脚本**
- 任务名称：如 `agenda-sync`
- 执行周期：**每 10 分钟**（与后台“自动同步频率”一致）
- 脚本内容：

```bash
/www/server/php/82/bin/php /www/wwwroot/agendas.limengjia.cn/scripts/sync.php >> /www/wwwroot/agendas.limengjia.cn/storage/logs/sync.log 2>&1
```

> 若 PHP 版本不是 8.2，请把路径中的 `82` 改成对应版本（如 74/80/81/83）。查看方式：`ls /www/server/php/`。

保存后可在“执行日志”里看到同步输出，例如 `日历名: OK (123 events)`。

---

## 9. HTTPS / 其它

- 在 **网站 → 站点设置 → SSL** 中申请 Let's Encrypt 免费证书（需要域名已解析且可访问），并开启**强制 HTTPS**。
- HTTPS 是微软 Graph 登录和邮件内链接正常工作的前提。
- 建议在 **网站 → 站点设置 → 防盗链/伪静态** 中，伪静态保持默认（宝塔会自动处理 `try_files`），不需要额外配置。

---

## 10. 常见问题 / Troubleshooting

| 现象 | 原因 / 处理 |
|---|---|
| 后台 500 或 `Failed opening required ...app/bootstrap.php` | 运行目录未设为 `/public`，或 open_basedir 只允许了 `public/`。回到第 3、4 步。 |
| 安装向导打不开 | `storage/` 不可写，或 `storage/installed.lock` 已存在。检查权限；确认要重装再删除该文件。 |
| 前台能开、后台进不去 | 用旧版本部署过 → 直接下载本仓库最新 `public/admin/*.php`（已修复路径），或运行 `upgrade_1.1.0.php`。 |
| 邮件发不出去 | 后台邮件设置 → 发送测试。检查端口/加密；邮箱服务商需要用“授权码/应用专用密码”。 |
| 日历页没有事件 | 后台日历管理 → 立即同步，看“上次同步/错误”列；Google 请用“私有 iCal 地址”（https://…）。 |
| 日历选择器卡在“加载中/Loading…” | 更新 `public/assets/js/app.js`、`public/ajax.php`、`app/util.php`、`app/graph.php`、`public/admin/calendars.php`、`public/admin/email.php`（1.2.0 的 AJAX 加固）。原因是 PHP 警告混入 JSON 响应导致前端无法解析；现在会显示错误而不是一直转圈。 |
| CalDAV 连接失败 / 401 | 检查服务器地址，并使用应用专用密码（iCloud/Nextcloud）。添加后具体的错误会显示在 后台 → 日历管理。 |
| 同步任务不执行 | 检查计划任务脚本里的 PHP 路径是否正确、storage/logs 是否可写、storage/logs/sync.log 有无报错。 |
| 登录 Outlook 报 `AADSTS500113: No reply address is registered` | Azure 应用注册中缺少/不匹配“重定向 URI”。去 **后台 → 日历管理 → 重定向 URI** 复制那串地址，再到 **Azure → 应用注册 → 你的应用 → 身份验证 → 添加平台 → Web → 重定向 URI** 粘贴并保存。必须完全一致（http/https、www、末尾不能有斜杠）。该地址由 **后台 → 通用设置 → 站点基础地址** 决定，若站点配置的域名与你当前访问域名不一致，地址会变化。 |

---

## 11. 升级已部署的版本 / Upgrade an installed site

如果服务器上已经部署了旧版本（例如 1.0.0，后台打不开），请把仓库根目录的 `upgrade_1.1.0.php` / `upgrade_1.2.0.php` 上传到站点根目录（与 `public/`、`app/` 同级），然后：

- **浏览器访问**：由于运行目录是 `/public`，升级文件放在根目录无法通过 URL 直接访问。请把 `public/upgrade_run.php` 上传到 `public/` 目录，然后访问：
  `https://agendas.limengjia.cn/upgrade_run.php`
- **或命令行**：`/www/server/php/82/bin/php /www/wwwroot/agendas.limengjia.cn/upgrade_1.2.0.php`

脚本会自动修正 1.2.0 的数据库变更（`calendars.ctype` 增加 `caldav`）并把 `app_version` 写入设置表。升级脚本会自动定位项目根目录，并会尝试用 `127.0.0.1` 连接数据库以避开 CLI 下的 `localhost` 套接字问题。**运行完请立刻删除** `upgrade_1.2.0.php` 和 `public/upgrade_run.php`（它们不设访问令牌，防止被他人再次调用）。详见 `readme.md` 第 9 节。
