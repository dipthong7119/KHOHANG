# KHOHANG
# Hệ thống quản lý kho hàng và giám sát tập trung

Ứng dụng web quản lý danh mục nhà cung cấp, mặt hàng, tồn kho và lịch sử nhập/xuất. Hệ thống được triển khai bằng Docker Compose, sử dụng MySQL để lưu dữ liệu và tích hợp Prometheus, Grafana, Loki, Promtail để giám sát dịch vụ và tra cứu log.

## Chức năng

- Thêm nhà cung cấp với tên, số điện thoại và email.
- Khai báo mặt hàng với SKU, tên, đơn giá và nhà cung cấp.
- Ghi nhận giao dịch nhập hoặc xuất kho kèm số lượng và ghi chú.
- Xem danh sách mặt hàng, nhà cung cấp, đơn giá và số lượng tồn.
- Xem lịch sử nhập/xuất gần đây.
- Theo dõi một số metrics của container và MySQL trên Grafana.
- Tra cứu log container trong Grafana thông qua Loki và Promtail.

> Đây là phiên bản phục vụ bài tập lớn và kiểm chứng triển khai. Chưa có bằng chứng nghiệm thu đầy đủ cho phân quyền người dùng, sao lưu/khôi phục, xuất kho vượt tồn, kiểm thử tải và các tình huống sử dụng đồng thời.

## Công nghệ sử dụng

- **Ứng dụng:** PHP 8.2-FPM, PDO MySQL
- **Cơ sở dữ liệu:** MySQL 8.0
- **Web server:** Nginx unprivileged
- **Triển khai:** Docker và Docker Compose
- **Metrics:** Prometheus, cAdvisor, MySQL exporter
- **Dashboard:** Grafana
- **Log:** Loki và Promtail

## Các dịch vụ và cổng truy cập

| Dịch vụ | Địa chỉ | Mục đích |
|---|---|---|
| Ứng dụng kho qua HTTP | `http://<SERVER_IP>:8088` | Giao diện quản lý kho |
| Ứng dụng kho qua HTTPS | `https://<SERVER_IP>:8448` | Giao diện HTTPS |
| Grafana | `http://<SERVER_IP>:3000` | Dashboard metrics và tra cứu log |
| Prometheus | `http://<SERVER_IP>:9091` | Truy vấn metrics |
| Loki | `3100` trong mạng Docker | Nhận và cung cấp log cho Grafana |
| MySQL | `3306` trong mạng Docker | Cơ sở dữ liệu nội bộ |

Thay `<SERVER_IP>` bằng địa chỉ IP của máy chủ. MySQL và Loki được các dịch vụ khác truy cập qua mạng Docker; không nên công khai các cổng nội bộ này ra Internet nếu không có nhu cầu.

## Cấu trúc thư mục

```text
.
├── app/
│   └── public/                 # Mã nguồn giao diện PHP
├── db/
│   └── init/                   # SQL khởi tạo cơ sở dữ liệu
├── grafana/
│   ├── dashboards/             # Dashboard Grafana
│   └── provisioning/           # Cấu hình provisioning nếu được sử dụng
├── loki/                       # Cấu hình và dữ liệu liên quan đến Loki
├── nginx/
│   ├── certs/                  # Chứng thư TLS
│   └── default.conf            # Cấu hình Nginx
├── prometheus/                 # Cấu hình Prometheus
├── promtail/                   # Cấu hình Promtail
├── Dockerfile                  # Image ứng dụng PHP-FPM
├── docker-compose.yml          # Khai báo các dịch vụ
└── .env                        # Biến môi trường, không đưa bí mật lên Git
```

Tên hoặc cấu trúc thư mục có thể thay đổi theo phiên bản repository. Hãy đối chiếu với các tệp đang có trong dự án.

## Yêu cầu

- Docker Engine
- Docker Compose
- OpenSSL nếu cần tự tạo chứng thư TLS
- Máy chủ Linux/Ubuntu hoặc môi trường có thể chạy Docker

## Cài đặt và khởi chạy

### 1. Lấy mã nguồn

Clone repository từ GitHub rồi vào thư mục dự án:

```bash
git clone <URL_REPOSITORY>
cd <TEN_THU_MUC_DU_AN>
```

Thay `<URL_REPOSITORY>` bằng URL repository GitHub của bạn.

### 2. Tạo tệp `.env`

Tạo tệp `.env` ở thư mục gốc và điền các biến môi trường mà `docker-compose.yml` sử dụng. Ví dụ:

```env
MYSQL_ROOT_PASSWORD=thay_bang_mat_khau_manh
MYSQL_DATABASE=inventory
MYSQL_USER=inventory_user
MYSQL_PASSWORD=thay_bang_mat_khau_manh_khac
MYSQLD_EXPORTER_PASSWORD=thay_bang_mat_khau_exporter
```

Kiểm tra `docker-compose.yml` để bảo đảm tên biến khớp với cấu hình của repository. Không sử dụng nguyên các giá trị ví dụ ở môi trường thật.

Giới hạn quyền đọc tệp `.env` trên Linux:

```bash
chmod 600 .env
```

Không commit `.env`, mật khẩu, khóa riêng hoặc tệp chứa thông tin bí mật lên GitHub. Có thể tạo một tệp `.env.example` chỉ chứa tên biến và giá trị mẫu không nhạy cảm để hướng dẫn cấu hình.

### 3. Chuẩn bị chứng thư HTTPS

Nếu repository chưa có các tệp chứng thư tại `nginx/certs/fullchain.pem` và `nginx/certs/privkey.pem`, có thể tạo chứng thư tự ký để kiểm thử:

```bash
mkdir -p nginx/certs

SERVER_IP=127.0.0.1

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout nginx/certs/privkey.pem \
  -out nginx/certs/fullchain.pem \
  -subj "/CN=${SERVER_IP}" \
  -addext "subjectAltName=IP:${SERVER_IP}"

chmod 600 nginx/certs/privkey.pem
```

Thay `127.0.0.1` bằng IP máy chủ khi truy cập từ máy khác. Chứng thư tự ký thường khiến trình duyệt hiển thị cảnh báo bảo mật; khi triển khai thật, hãy dùng chứng thư từ CA đáng tin cậy.

### 4. Khởi chạy hệ thống

```bash
docker compose up -d --build
```

Kiểm tra trạng thái container:

```bash
docker compose ps
```

Theo dõi log dịch vụ:

```bash
docker compose logs -f
```

Có thể xem log của một dịch vụ cụ thể, ví dụ:

```bash
docker compose logs -f app
docker compose logs -f db
docker compose logs -f nginx
docker compose logs -f loki
```

Nếu tên dịch vụ trong `docker-compose.yml` khác, dùng tên hiển thị trong tệp Compose.

### 5. Mở giao diện

- Ứng dụng kho: `http://<SERVER_IP>:8088`
- Grafana: `http://<SERVER_IP>:3000`
- Prometheus: `http://<SERVER_IP>:9091`

Thông tin đăng nhập Grafana phụ thuộc vào biến môi trường hoặc cấu hình trong repository. Hãy kiểm tra cấu hình hiện tại và đổi mật khẩu mặc định trước khi triển khai sử dụng chung.

## Cơ sở dữ liệu

Cơ sở dữ liệu sử dụng tên `inventory` trong cấu hình mẫu, gồm các bảng nghiệp vụ chính:

- **`suppliers`**: thông tin nhà cung cấp.
- **`products`**: SKU, tên mặt hàng, nhà cung cấp, đơn giá và số lượng tồn.
- **`stock_movements`**: mặt hàng, loại giao dịch `IN` hoặc `OUT`, số lượng, ghi chú và thời điểm tạo.

SKU của mặt hàng được thiết kế là duy nhất. Cơ sở dữ liệu lưu số lượng hiện tại ở bảng `products` và lịch sử biến động ở bảng `stock_movements`.

## Giám sát và tra cứu log

Grafana được sử dụng để hiển thị metrics từ Prometheus và truy vấn log từ Loki.

Một số metric có thể dùng để kiểm tra:

```promql
container_memory_usage_bytes
```

```promql
mysql_global_status_threads_connected
```

Truy vấn log cơ bản trong Grafana Explore:

```logql
{job="docker"}
```

Lọc các dòng log có nội dung chứa `error`:

```logql
{job="docker"} |= "error"
```

Log lịch sử có thể chứa thông báo lỗi từ các lần cấu hình hoặc khởi động trước đó. Hãy xem mốc thời gian và trạng thái mới nhất trước khi đánh giá tình trạng hệ thống.

## Dừng hệ thống

```bash
docker compose down
```

Lệnh trên dừng và xóa các container của dự án. Dữ liệu trong named volumes thường vẫn được giữ lại. Không xóa volume nếu chưa có bản sao lưu hoặc chưa chắc chắn muốn xóa dữ liệu cơ sở dữ liệu.

## Bảo mật

- Đặt mật khẩu mạnh và giữ bí mật trong `.env`.
- Không commit `.env`, khóa riêng hoặc mật khẩu lên GitHub.
- Giới hạn quyền truy cập vào Grafana, Prometheus, Loki và máy chủ.
- Chỉ mở các cổng cần thiết trên firewall.
- Dùng chứng thư TLS từ CA đáng tin cậy khi triển khai thật.
- Thiết lập lịch sao lưu và thử khôi phục dữ liệu.
- Kiểm tra quyền chạy của từng container và chính sách lưu trữ log.

## Giới hạn hiện tại và hướng phát triển

Các nội dung sau cần được kiểm thử hoặc bổ sung trước khi dùng như một hệ thống kho vận hành thực tế:

- Xác minh số lượng xuất phải lớn hơn 0 và không vượt tồn khả dụng.
- Bảo đảm cập nhật tồn và ghi lịch sử giao dịch trong cùng một transaction.
- Kiểm thử gửi giao dịch lặp và nhiều người thao tác đồng thời.
- Bổ sung tài khoản, phân quyền và thông tin người thực hiện giao dịch.
- Bổ sung mã chứng từ, kiểm kê và quy trình điều chỉnh tồn.
- Thiết lập backup/restore, cảnh báo và thời hạn lưu log.
- Kiểm thử tải và phục hồi sau sự cố.

## Tác giả

- Sinh viên: `<Họ và tên>`
- MSSV: `<Mã số sinh viên>`
- Lớp: `<Lớp>`
