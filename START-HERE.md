# 🚀 BYD Voice Assistant - كيف تشغّل المشروع

## كل ما تفتح الجهاز، اعمل هالخطوتين بس:

### 1. افتح XAMPP واضغط Start على Apache
- Redis يشتغل تلقائي (مثبّت كـ Windows Service) ✅
- Apache لازم تضغط Start يدوياً

### 2. شغّل الـ Frontend
افتح Terminal في مجلد `byd-ai-concierge-main`:
```bash
cd C:\Users\hp\OneDrive\Desktop\BYD\byd-ai-concierge-main
npm run dev
```

---

## 🔗 الروابط
| Service  | URL                        |
|----------|----------------------------|
| Frontend | http://localhost:5173       |
| Backend  | http://localhost:8080       |

---

## ❓ مشاكل شائعة

### Redis مش شغّال؟
افتح CMD واكتب:
```
redis-cli ping
```
لازم يرد `PONG`. إذا لا، شغّله:
```
net start Redis
```

### Apache مش شغّال على 8080؟
تأكد إن Apache أخضر في XAMPP Control Panel.
