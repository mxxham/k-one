import { useState, FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { User, Lock, LogIn, AlertCircle } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { departmentHome } from '@/lib/api';

export default function Login() {
  const { login, isAuthenticated, department } = useAuth();
  const navigate = useNavigate();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  if (isAuthenticated) {
    navigate(departmentHome(department), { replace: true });
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const u = await login(username, password);
      navigate(departmentHome(u?.department), { replace: true });
    } catch (err: any) {
      setError(err.message || 'Username atau password salah');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-[#f0f4f4] p-6 font-inter">
      <div className="login-wrapper w-full max-w-[860px] bg-white rounded-[20px] shadow-[0_20px_60px_rgba(2,103,102,0.15)] overflow-hidden min-h-[520px] flex">
        
        {/* Brand Panel - Left Side */}
        <div className="login-brand flex-[0_0_340px] bg-[#026766] flex items-center justify-center p-10 relative overflow-hidden border-r-0">
          <div className="absolute top-[-80px] right-[-80px] w-[260px] h-[260px] bg-white/8 rounded-full" />
          <div className="absolute bottom-[-60px] left-[-60px] w-[200px] h-[200px] bg-white/5 rounded-full" />
          <div className="relative z-10 w-full">
            <img 
              src="/assets/logo.png" 
              alt="K-one" 
              className="w-[72%] max-w-[220px] object-contain mx-auto block" 
            />
          </div>
        </div>

        {/* Form Panel - Right Side */}
        <div className="login-form-panel flex-1 flex flex-col justify-center p-[48px_44px]">
          <h2 className="text-[1.5rem] font-bold text-[#0f1f1f] mb-1">Selamat Datang</h2>
          <p className="subtitle text-[.85rem] text-[#6b7280] mb-8">Masuk ke akun K-one Anda</p>

          {error && (
            <div className="alert-error flex items-center gap-2 p-[10px_14px] rounded-[8px] text-[.85rem] mb-5 bg-[#e6f7f7] border border-[#b2e5e5] text-[#013d3c]">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} noValidate>
            <div className="form-group mb-5">
              <label className="block text-[.8rem] font-semibold text-[#374151] mb-1.5 uppercase tracking-[.4px]">
                Username
              </label>
              <div className="input-wrap relative">
                <User className="w-4 h-4 absolute left-[14px] top-1/2 -translate-y-1/2 text-[#9ca3af]" />
                <input
                  type="text"
                  name="username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  placeholder="Masukkan username"
                  required
                  autoFocus
                  className="w-full pl-[38px] pr-[14px] py-[11px] border-[1.5px] border-[#d1d5db] rounded-[10px] text-[.9rem] text-[#0f1f1f] outline-none transition-[border-color,box-shadow] bg-[#fafafa] font-inherit focus:border-[#026766] focus:ring-[3px] focus:ring-[#026766]/12 focus:bg-white"
                />
              </div>
            </div>

            <div className="form-group mb-5">
              <label className="block text-[.8rem] font-semibold text-[#374151] mb-1.5 uppercase tracking-[.4px]">
                Password
              </label>
              <div className="input-wrap relative">
                <Lock className="w-4 h-4 absolute left-[14px] top-1/2 -translate-y-1/2 text-[#9ca3af]" />
                <input
                  type="password"
                  name="password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Masukkan password"
                  required
                  className="w-full pl-[38px] pr-[14px] py-[11px] border-[1.5px] border-[#d1d5db] rounded-[10px] text-[.9rem] text-[#0f1f1f] outline-none transition-[border-color,box-shadow] bg-[#fafafa] font-inherit focus:border-[#026766] focus:ring-[3px] focus:ring-[#026766]/12 focus:bg-white"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="btn-login w-full py-[13px] bg-[#026766] text-white border-0 rounded-[10px] text-[.95rem] font-bold font-inherit cursor-pointer transition-[background,transform] flex items-center justify-center gap-2 mt-2 hover:bg-[#014f4e] active:scale-[.99] disabled:opacity-60 disabled:cursor-not-allowed"
            >
              <LogIn className="w-4 h-4" />
              {loading ? 'Memproses...' : 'Masuk'}
            </button>
          </form>

          <div className="login-footer mt-7 text-center text-[.78rem] text-[#9ca3af]">
            &copy; {new Date().getFullYear()} K-one. All rights reserved.
          </div>
        </div>
      </div>
    </div>
  );
}
