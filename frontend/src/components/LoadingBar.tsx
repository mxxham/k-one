import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';

export default function LoadingBar() {
  const [loading, setLoading] = useState(false);
  const location = useLocation();

  useEffect(() => {
    setLoading(true);
    const t = setTimeout(() => setLoading(false), 300);
    return () => clearTimeout(t);
  }, [location.pathname]);

  if (!loading) return null;
  return (
    <div className="fixed top-0 left-0 right-0 h-1 bg-brand-500 z-[100] animate-pulse" />
  );
}
