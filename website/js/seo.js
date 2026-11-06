(function () {
  const path = location.pathname;
  const url = location.origin + path;
  let canonical = document.querySelector('link[rel="canonical"]');
  if (!canonical) {
    canonical = document.createElement('link');
    canonical.rel = 'canonical';
    document.head.append(canonical);
  }
  canonical.href = url;
  const graph = {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'Organization',
        name: 'Unorthodox Technologies',
        url: location.origin + '/website/index.html',
        founder: { '@type': 'Person', name: 'Marte Flores Jr.' },
        member: [
          { '@type': 'Person', name: 'Marte Flores Jr.', jobTitle: 'Founder' },
          { '@type': 'Person', name: 'Justine Reyes', jobTitle: 'Co-founder' },
          { '@type': 'Person', name: 'Melvin Ramos', jobTitle: 'Co-founder' },
          { '@type': 'Person', name: 'Clarenz Geronimo', jobTitle: 'Co-founder' }
        ]
      },
      {
        '@type': 'SoftwareApplication',
        name: 'ORBIT',
        applicationCategory: 'BusinessApplication',
        operatingSystem: 'Android',
        author: { '@type': 'Organization', name: 'Unorthodox Technologies' },
        offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' }
      }
    ]
  };
  const script = document.createElement('script');
  script.type = 'application/ld+json';
  script.textContent = JSON.stringify(graph);
  document.head.append(script);
})();
