import React, { useState, useEffect } from 'react';
import Select from 'react-select';

/**
 * Custom Tailwind-aligned styles for react-select
 */
const customSelectStyles = {
  control: (base, state) => ({
    ...base,
    minHeight: '42px',
    borderRadius: '0.75rem', // rounded-xl
    borderColor: state.isFocused ? '#0f766e' : '#cbd5e1', // teal-700 : slate-300
    backgroundColor: state.isDisabled ? '#f8fafc' : '#ffffff',
    boxShadow: state.isFocused ? '0 0 0 3px rgba(15, 118, 110, 0.15)' : 'none',
    '&:hover': {
      borderColor: state.isFocused ? '#0f766e' : '#94a3b8',
    },
    fontSize: '0.875rem',
    cursor: state.isDisabled ? 'not-allowed' : 'pointer',
  }),
  menu: (base) => ({
    ...base,
    borderRadius: '0.75rem',
    overflow: 'hidden',
    boxShadow: '0 10px 25px -5px rgba(15, 23, 42, 0.12), 0 4px 10px -2px rgba(15, 23, 42, 0.06)',
    border: '1px solid #e2e8f0',
    zIndex: 60,
  }),
  option: (base, state) => ({
    ...base,
    fontSize: '0.875rem',
    backgroundColor: state.isSelected
      ? '#0f766e'
      : state.isFocused
      ? '#f0fdfa'
      : '#ffffff',
    color: state.isSelected ? '#ffffff' : '#1e293b',
    cursor: 'pointer',
    padding: '8px 12px',
  }),
  placeholder: (base, state) => ({
    ...base,
    color: state.isDisabled ? '#cbd5e1' : '#94a3b8',
    fontSize: '0.875rem',
  }),
  singleValue: (base) => ({
    ...base,
    color: '#0f172a',
    fontWeight: 500,
  }),
};

/**
 * Reusable Philippine Cascading Address Selector (Region -> Province -> City/Municipality -> Barangay)
 * Powered by the PSGC API (https://psgc.gitlab.io/api)
 *
 * @param {Object} props
 * @param {(address: {
 *   region: { code: string, name: string } | null,
 *   province: { code: string, name: string } | null,
 *   city: { code: string, name: string } | null,
 *   barangay: { code: string, name: string } | null
 * }) => void} [props.onChange] - Callback fired whenever any selection changes
 * @param {boolean} [props.useNativeSelect=false] - Set true to use native HTML <select> instead of react-select
 * @param {string} [props.className=''] - Optional wrapper className
 */
export default function AddressSelector({
  onChange,
  useNativeSelect = false,
  className = '',
}) {
  // Option lists fetched from PSGC API
  const [regions, setRegions] = useState([]);
  const [provinces, setProvinces] = useState([]);
  const [cities, setCities] = useState([]);
  const [barangays, setBarangays] = useState([]);

  // Selected values ({ value: code, label: name, isNcrVirtual?: boolean } | null)
  const [selectedRegion, setSelectedRegion] = useState(null);
  const [selectedProvince, setSelectedProvince] = useState(null);
  const [selectedCity, setSelectedCity] = useState(null);
  const [selectedBarangay, setSelectedBarangay] = useState(null);

  // Loading states for each level
  const [loadingRegions, setLoadingRegions] = useState(false);
  const [loadingProvinces, setLoadingProvinces] = useState(false);
  const [loadingCities, setLoadingCities] = useState(false);
  const [loadingBarangays, setLoadingBarangays] = useState(false);

  // Error message state
  const [error, setError] = useState(null);

  // Helper to sort PSGC items alphabetically and map to { value, label }
  const formatOptions = (items = []) =>
    [...items]
      .sort((a, b) => a.name.localeCompare(b.name))
      .map((item) => ({
        value: item.code,
        label: item.name,
      }));

  // 1. Fetch Regions on Mount
  useEffect(() => {
    let isMounted = true;
    const fetchRegions = async () => {
      setLoadingRegions(true);
      setError(null);
      try {
        const res = await fetch('https://psgc.gitlab.io/api/regions');
        if (!res.ok) throw new Error('Failed to load regions from PSGC API.');
        const data = await res.json();
        if (isMounted) {
          setRegions(formatOptions(data));
        }
      } catch (err) {
        if (isMounted) setError(err.message || 'Unable to fetch regions.');
      } finally {
        if (isMounted) setLoadingRegions(false);
      }
    };

    fetchRegions();
    return () => {
      isMounted = false;
    };
  }, []);

  // 2. When Region Changes -> Clear Children & Fetch Provinces
  useEffect(() => {
    // Reset child selections and option lists
    setSelectedProvince(null);
    setSelectedCity(null);
    setSelectedBarangay(null);
    setProvinces([]);
    setCities([]);
    setBarangays([]);

    if (!selectedRegion?.value) return;

    let isMounted = true;
    const fetchProvinces = async () => {
      setLoadingProvinces(true);
      setError(null);
      try {
        const regionCode = selectedRegion.value;
        const res = await fetch(
          `https://psgc.gitlab.io/api/regions/${regionCode}/provinces`
        );
        if (!res.ok) throw new Error('Failed to load provinces.');
        const data = await res.json();

        if (isMounted) {
          if (Array.isArray(data) && data.length > 0) {
            setProvinces(formatOptions(data));
          } else {
            // Handle NCR (National Capital Region), which has no provinces in PSGC
            const ncrOption = {
              value: regionCode,
              label: 'Metro Manila (NCR)',
              isNcrVirtual: true,
            };
            setProvinces([ncrOption]);
            setSelectedProvince(ncrOption);
          }
        }
      } catch (err) {
        if (isMounted) setError(err.message || 'Unable to fetch provinces.');
      } finally {
        if (isMounted) setLoadingProvinces(false);
      }
    };

    fetchProvinces();
    return () => {
      isMounted = false;
    };
  }, [selectedRegion]);

  // 3. When Province Changes -> Clear City/Barangay & Fetch Cities/Municipalities
  useEffect(() => {
    setSelectedCity(null);
    setSelectedBarangay(null);
    setCities([]);
    setBarangays([]);

    if (!selectedProvince?.value) return;

    let isMounted = true;
    const fetchCities = async () => {
      setLoadingCities(true);
      setError(null);
      try {
        const provinceCode = selectedProvince.value;
        const endpoint = selectedProvince.isNcrVirtual
          ? `https://psgc.gitlab.io/api/regions/${provinceCode}/cities-municipalities`
          : `https://psgc.gitlab.io/api/provinces/${provinceCode}/cities-municipalities`;

        const res = await fetch(endpoint);
        if (!res.ok) throw new Error('Failed to load cities/municipalities.');
        const data = await res.json();
        if (isMounted) {
          setCities(formatOptions(data));
        }
      } catch (err) {
        if (isMounted) setError(err.message || 'Unable to fetch cities/municipalities.');
      } finally {
        if (isMounted) setLoadingCities(false);
      }
    };

    fetchCities();
    return () => {
      isMounted = false;
    };
  }, [selectedProvince]);

  // 4. When City/Municipality Changes -> Clear Barangay & Fetch Barangays
  useEffect(() => {
    setSelectedBarangay(null);
    setBarangays([]);

    if (!selectedCity?.value) return;

    let isMounted = true;
    const fetchBarangays = async () => {
      setLoadingBarangays(true);
      setError(null);
      try {
        const cityCode = selectedCity.value;
        const res = await fetch(
          `https://psgc.gitlab.io/api/cities-municipalities/${cityCode}/barangays`
        );
        if (!res.ok) throw new Error('Failed to load barangays.');
        const data = await res.json();
        if (isMounted) {
          setBarangays(formatOptions(data));
        }
      } catch (err) {
        if (isMounted) setError(err.message || 'Unable to fetch barangays.');
      } finally {
        if (isMounted) setLoadingBarangays(false);
      }
    };

    fetchBarangays();
    return () => {
      isMounted = false;
    };
  }, [selectedCity]);

  // 5. Notify parent component whenever any selection updates
  useEffect(() => {
    if (typeof onChange === 'function') {
      onChange({
        region: selectedRegion
          ? { code: selectedRegion.value, name: selectedRegion.label }
          : null,
        province: selectedProvince
          ? { code: selectedProvince.value, name: selectedProvince.label }
          : null,
        city: selectedCity
          ? { code: selectedCity.value, name: selectedCity.label }
          : null,
        barangay: selectedBarangay
          ? { code: selectedBarangay.value, name: selectedBarangay.label }
          : null,
      });
    }
  }, [selectedRegion, selectedProvince, selectedCity, selectedBarangay, onChange]);

  // Shared Tailwind classes for native <select> fallback
  const nativeSelectClass =
    'w-full px-3.5 py-2.5 text-sm font-medium text-slate-800 bg-white border border-slate-300 rounded-xl shadow-2xs transition focus:outline-none focus:ring-2 focus:ring-teal-600/20 focus:border-teal-700 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed';

  return (
    <div className={`w-full space-y-3 ${className}`}>
      {error && (
        <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-700 flex items-center justify-between">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => setError(null)}
            className="text-rose-500 hover:text-rose-800 font-bold ml-2"
          >
            &times;
          </button>
        </div>
      )}

      {/* Responsive Grid: 1 column on mobile, 2 on tablet, 4 on desktop */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* 1. REGION */}
        <div>
          <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
            Region <span className="text-rose-500">*</span>
          </label>
          {useNativeSelect ? (
            <select
              value={selectedRegion?.value || ''}
              disabled={loadingRegions}
              onChange={(e) => {
                const found = regions.find((r) => r.value === e.target.value) || null;
                setSelectedRegion(found);
              }}
              className={nativeSelectClass}
            >
              <option value="">
                {loadingRegions ? 'Loading regions...' : 'Select Region'}
              </option>
              {regions.map((reg) => (
                <option key={reg.value} value={reg.value}>
                  {reg.label}
                </option>
              ))}
            </select>
          ) : (
            <Select
              instanceId="psgc-region-select"
              options={regions}
              value={selectedRegion}
              onChange={(opt) => setSelectedRegion(opt)}
              isLoading={loadingRegions}
              isClearable
              isSearchable
              placeholder={loadingRegions ? 'Loading regions...' : 'Search or select Region...'}
              styles={customSelectStyles}
            />
          )}
          <input type="hidden" name="region" value={selectedRegion?.label || ''} />
          <input type="hidden" name="region_code" value={selectedRegion?.value || ''} />
        </div>

        {/* 2. PROVINCE */}
        <div>
          <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
            Province <span className="text-rose-500">*</span>
          </label>
          {useNativeSelect ? (
            <select
              value={selectedProvince?.value || ''}
              disabled={!selectedRegion || loadingProvinces}
              onChange={(e) => {
                const found = provinces.find((p) => p.value === e.target.value) || null;
                setSelectedProvince(found);
              }}
              className={nativeSelectClass}
            >
              <option value="">
                {!selectedRegion
                  ? 'Select Region first'
                  : loadingProvinces
                  ? 'Loading provinces...'
                  : 'Select Province'}
              </option>
              {provinces.map((prov) => (
                <option key={prov.value} value={prov.value}>
                  {prov.label}
                </option>
              ))}
            </select>
          ) : (
            <Select
              instanceId="psgc-province-select"
              options={provinces}
              value={selectedProvince}
              onChange={(opt) => setSelectedProvince(opt)}
              isDisabled={!selectedRegion || loadingProvinces}
              isLoading={loadingProvinces}
              isClearable
              isSearchable
              placeholder={
                !selectedRegion
                  ? 'Select Region first'
                  : loadingProvinces
                  ? 'Loading provinces...'
                  : 'Search or select Province...'
              }
              styles={customSelectStyles}
            />
          )}
          <input type="hidden" name="province" value={selectedProvince?.label || ''} />
          <input type="hidden" name="province_code" value={selectedProvince?.value || ''} />
        </div>

        {/* 3. CITY / MUNICIPALITY */}
        <div>
          <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
            City / Municipality <span className="text-rose-500">*</span>
          </label>
          {useNativeSelect ? (
            <select
              value={selectedCity?.value || ''}
              disabled={!selectedProvince || loadingCities}
              onChange={(e) => {
                const found = cities.find((c) => c.value === e.target.value) || null;
                setSelectedCity(found);
              }}
              className={nativeSelectClass}
            >
              <option value="">
                {!selectedProvince
                  ? 'Select Province first'
                  : loadingCities
                  ? 'Loading cities/municipalities...'
                  : 'Select City / Municipality'}
              </option>
              {cities.map((city) => (
                <option key={city.value} value={city.value}>
                  {city.label}
                </option>
              ))}
            </select>
          ) : (
            <Select
              instanceId="psgc-city-select"
              options={cities}
              value={selectedCity}
              onChange={(opt) => setSelectedCity(opt)}
              isDisabled={!selectedProvince || loadingCities}
              isLoading={loadingCities}
              isClearable
              isSearchable
              placeholder={
                !selectedProvince
                  ? 'Select Province first'
                  : loadingCities
                  ? 'Loading cities...'
                  : 'Search City / Municipality...'
              }
              styles={customSelectStyles}
            />
          )}
          <input type="hidden" name="municipality" value={selectedCity?.label || ''} />
          <input type="hidden" name="city_code" value={selectedCity?.value || ''} />
        </div>

        {/* 4. BARANGAY */}
        <div>
          <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">
            Barangay <span className="text-rose-500">*</span>
          </label>
          {useNativeSelect ? (
            <select
              value={selectedBarangay?.value || ''}
              disabled={!selectedCity || loadingBarangays}
              onChange={(e) => {
                const found = barangays.find((b) => b.value === e.target.value) || null;
                setSelectedBarangay(found);
              }}
              className={nativeSelectClass}
            >
              <option value="">
                {!selectedCity
                  ? 'Select City/Municipality first'
                  : loadingBarangays
                  ? 'Loading barangays...'
                  : 'Select Barangay'}
              </option>
              {barangays.map((brgy) => (
                <option key={brgy.value} value={brgy.value}>
                  {brgy.label}
                </option>
              ))}
            </select>
          ) : (
            <Select
              instanceId="psgc-barangay-select"
              options={barangays}
              value={selectedBarangay}
              onChange={(opt) => setSelectedBarangay(opt)}
              isDisabled={!selectedCity || loadingBarangays}
              isLoading={loadingBarangays}
              isClearable
              isSearchable
              placeholder={
                !selectedCity
                  ? 'Select City first'
                  : loadingBarangays
                  ? 'Loading barangays...'
                  : 'Search Barangay...'
              }
              styles={customSelectStyles}
            />
          )}
          <input type="hidden" name="barangay" value={selectedBarangay?.label || ''} />
          <input type="hidden" name="barangay_code" value={selectedBarangay?.value || ''} />
        </div>
      </div>
    </div>
  );
}

